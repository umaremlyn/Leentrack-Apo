<?php
declare(strict_types=1);

namespace LeenTrack\Mail;

/** Production default on cPanel (uses the host's sendmail via mail()). SMTP/Resend can replace it behind the same interface. */
final class NativeMailer implements Mailer
{
    public function __construct(private readonly string $from, private readonly \LeenTrack\Core\Logger $log)
    {
    }

    public function send(string $to, string $subject, string $text): void
    {
        // Header-injection defence: addresses/subjects must be single-line.
        if (preg_match('/[\r\n]/', $to . $subject . $this->from)) {
            throw new \InvalidArgumentException('Invalid mail header.');
        }
        $headers = "From: {$this->from}\r\nContent-Type: text/plain; charset=UTF-8\r\nMIME-Version: 1.0";
        $ok = @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $text, $headers);
        if (!$ok) {
            $this->log->error('mail delivery failed', ['to_domain' => substr(strrchr($to, '@') ?: '', 1)]);
        }
    }
}
