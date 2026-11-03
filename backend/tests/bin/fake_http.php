<?php
declare(strict_types=1);

/**
 * One-request HTTP server for tests. Usage: fake_http.php <portFile> <requestFile> <status> <jsonBody>
 * Records "METHOD path", headers and body into <requestFile> as JSON and answers with the given status/body.
 */
[, $portFile, $outFile, $status, $body] = $argv;
$srv = stream_socket_server('tcp://127.0.0.1:0');
file_put_contents($portFile, (string) parse_url('tcp://' . stream_socket_get_name($srv, false), PHP_URL_PORT));
$c = @stream_socket_accept($srv, 20);
if ($c === false) { file_put_contents($outFile, json_encode(['error' => 'no client'])); exit(1); }
$head = '';
while (($l = fgets($c)) !== false) { $head .= $l; if ($l === "\r\n") { break; } }
preg_match('/^(\S+) (\S+)/', $head, $m);
preg_match('/content-length:\s*(\d+)/i', $head, $cl);
$reqBody = isset($cl[1]) ? (string) fread($c, (int) $cl[1]) : '';
fwrite($c, "HTTP/1.1 $status X\r\nContent-Type: application/json\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n\r\n$body");
fclose($c);
file_put_contents($outFile, json_encode(['line' => ($m[1] ?? '') . ' ' . ($m[2] ?? ''), 'head' => $head, 'body' => $reqBody]));
