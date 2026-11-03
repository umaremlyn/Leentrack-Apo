<?php
declare(strict_types=1);

/**
 * Minimal SMTP server for tests (one connection). Usage: fake_smtp.php <portFile> <transcriptFile> [login|reject-rcpt]
 * Writes the chosen port to <portFile> once listening, then the full client transcript to <transcriptFile> as JSON.
 */
[, $portFile, $outFile] = $argv;
$mode = $argv[3] ?? 'plain';
$srv = stream_socket_server('tcp://127.0.0.1:0', $errno, $err);
file_put_contents($portFile, (string) parse_url('tcp://' . stream_socket_get_name($srv, false), PHP_URL_PORT));
stream_set_blocking($srv, true);
$c = @stream_socket_accept($srv, 20);
$log = [];
if ($c === false) { file_put_contents($outFile, json_encode(['error' => 'no client'])); exit(1); }
$w = fn (string $s) => fwrite($c, $s . "\r\n");
$w('220 fake.test ESMTP');
$data = false; $buf = '';
while (($line = fgets($c, 8192)) !== false) {
    if ($data) {
        $buf .= $line;
        if (str_ends_with($buf, "\r\n.\r\n")) { $data = false; $log[] = ['DATA_BODY' => substr($buf, 0, -5)]; $buf = ''; $w('250 queued'); }
        continue;
    }
    $cmd = rtrim($line, "\r\n");
    $log[] = $cmd;
    $u = strtoupper($cmd);
    if (str_starts_with($u, 'EHLO')) { fwrite($c, "250-fake.test\r\n250-AUTH " . ($mode === 'login' ? 'LOGIN' : 'PLAIN LOGIN') . "\r\n250 8BITMIME\r\n"); }
    elseif (str_starts_with($u, 'AUTH PLAIN')) { $w('235 ok'); }
    elseif ($u === 'AUTH LOGIN') { $w('334 VXNlcm5hbWU6'); $log[] = rtrim((string) fgets($c), "\r\n"); $w('334 UGFzc3dvcmQ6'); $log[] = rtrim((string) fgets($c), "\r\n"); $w('235 ok'); }
    elseif (str_starts_with($u, 'MAIL FROM')) { $w('250 ok'); }
    elseif (str_starts_with($u, 'RCPT TO')) { $mode === 'reject-rcpt' ? $w('550 no such user') : $w('250 ok'); }
    elseif ($u === 'DATA') { $w('354 go'); $data = true; }
    elseif ($u === 'QUIT') { $w('221 bye'); break; }
    else { $w('502 unknown'); }
}
file_put_contents($outFile, json_encode($log));
