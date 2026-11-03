<?php
declare(strict_types=1);

namespace LeenTrack\Mail;

use LeenTrack\Core\Logger;

/**
 * Plain SMTP client (RFC 5321) with STARTTLS or implicit TLS and AUTH PLAIN/LOGIN. Needs only PHP sockets, so it works on shared hosting
 * and replaces mail() when the host's sendmail has no SPF/DKIM alignment (docs G-22). One connection per message; failures are logged
 * WITHOUT credentials or message content and never thrown to the caller (a failed e-mail must not turn a successful action into a 500).
 */
final class SmtpMailer implements Mailer
{
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $secure,          // 'tls' (STARTTLS), 'ssl' (implicit TLS) or 'none'
        private readonly string $user,
        private readonly string $pass,
        private readonly string $from,
        private readonly Logger $log,
        private readonly int $timeout = 10,
        private readonly string $ehloName = 'leentrack',
    ) {
    }

    public function send(string $to, string $subject, string $text): void
    {
        if (preg_match('/[\r\n\0]/', $to . $subject . $this->from)) { throw new \InvalidArgumentException('Invalid mail header.'); }
        $fromAddr = $this->address($this->from);
        if (!filter_var($to, FILTER_VALIDATE_EMAIL) || $fromAddr === null) { throw new \InvalidArgumentException('Invalid e-mail address.'); }
        $sock = null;
        try {
            $sock = $this->connect();
            $this->expect($sock, [220]);
            $caps = $this->ehlo($sock);
            if ($this->secure === 'tls') {
                if (!str_contains($caps, 'STARTTLS')) { throw new \RuntimeException('server does not offer STARTTLS'); }
                $this->cmd($sock, 'STARTTLS', [220]);
                if (!@stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) { throw new \RuntimeException('TLS negotiation failed'); }
                $caps = $this->ehlo($sock);
            }
            if ($this->user !== '') {
                if (str_contains($caps, 'AUTH') && preg_match('/AUTH[^\n]*\bPLAIN\b/', $caps)) {
                    $this->cmd($sock, 'AUTH PLAIN ' . base64_encode("\0{$this->user}\0{$this->pass}"), [235]);
                } else {
                    $this->cmd($sock, 'AUTH LOGIN', [334]);
                    $this->cmd($sock, base64_encode($this->user), [334]);
                    $this->cmd($sock, base64_encode($this->pass), [235]);
                }
            }
            $this->cmd($sock, "MAIL FROM:<$fromAddr>", [250]);
            $this->cmd($sock, "RCPT TO:<$to>", [250, 251]);
            $this->cmd($sock, 'DATA', [354]);
            $this->raw($sock, $this->message($to, $subject, $text) . "\r\n.\r\n");
            $this->expect($sock, [250]);
            $this->cmd($sock, 'QUIT', [221]);
        } catch (\Throwable $e) {
            $this->log->error('smtp delivery failed', ['to_domain' => substr(strrchr($to, '@') ?: '', 1), 'reason' => preg_replace('/[\r\n]+/', ' ', substr($e->getMessage(), 0, 120))]);
        } finally {
            if (is_resource($sock)) { @fclose($sock); }
        }
    }

    private function message(string $to, string $subject, string $text): string
    {
        $body = rtrim(preg_replace('/\r\n|\r|\n/', "\r\n", $text), "\r\n");
        $body = preg_replace('/^\./m', '..', $body);                       // dot-stuffing: a lone "." line must not end the message
        $domain = substr(strrchr($this->address($this->from) ?? '', '@') ?: '@localhost', 1);
        return implode("\r\n", [
            'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000',
            "From: {$this->from}",
            "To: <$to>",
            'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $domain . '>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            '',
            rtrim(chunk_split(base64_encode($body), 76, "\r\n")),
        ]);
    }

    private function address(string $from): ?string
    {
        $a = preg_match('/<([^<>]+)>/', $from, $m) ? $m[1] : trim($from);
        return filter_var($a, FILTER_VALIDATE_EMAIL) ? $a : null;
    }

    /** @return resource */
    private function connect()
    {
        $target = ($this->secure === 'ssl' ? 'ssl://' : 'tcp://') . $this->host . ':' . $this->port;
        $s = @stream_socket_client($target, $errno, $err, $this->timeout, STREAM_CLIENT_CONNECT, stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]));
        if ($s === false) { throw new \RuntimeException("cannot connect ($errno)"); }
        stream_set_timeout($s, $this->timeout);
        return $s;
    }

    /** @param resource $s */
    private function ehlo($s): string
    {
        return $this->cmd($s, 'EHLO ' . $this->ehloName, [250]);
    }

    /** @param resource $s @param list<int> $ok */
    private function cmd($s, string $line, array $ok): string
    {
        $this->raw($s, $line . "\r\n");
        return $this->expect($s, $ok);
    }

    /** @param resource $s */
    private function raw($s, string $data): void
    {
        if (@fwrite($s, $data) === false) { throw new \RuntimeException('write failed'); }
    }

    /** Reads a (possibly multi-line) reply and checks its code. @param resource $s @param list<int> $ok */
    private function expect($s, array $ok): string
    {
        $all = '';
        while (($line = fgets($s, 1024)) !== false) {
            $all .= $line;
            if (strlen($line) < 4 || $line[3] !== '-') { break; }
        }
        if ($all === '' || !in_array((int) substr($all, 0, 3), $ok, true)) { throw new \RuntimeException('unexpected reply ' . substr($all, 0, 3)); }
        return $all;
    }
}
