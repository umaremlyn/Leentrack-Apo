<?php
declare(strict_types=1);

namespace LeenTrack\Core;

final class Response
{
    /** @param array<string,string> $headers */
    public function __construct(
        public int $status = 200,
        public string $body = '',
        public array $headers = [],
    ) {
    }

    /** @param array<string,mixed> $meta */
    public static function ok(mixed $data, array $meta = [], int $status = 200): self
    {
        return self::json($status, ['ok' => true, 'data' => $data, 'meta' => $meta ?: new \stdClass()]);
    }

    /** @param array<string,list<string>> $fields */
    public static function error(string $code, string $message, int $status, array $fields = [], ?string $requestId = null): self
    {
        $err = ['code' => $code, 'message' => $message];
        if ($fields) {
            $err['fields'] = $fields;
        }
        return self::json($status, ['ok' => false, 'error' => $err, 'meta' => $requestId ? ['request_id' => $requestId] : new \stdClass()]);
    }

    /** @param array<string,mixed> $payload */
    public static function json(int $status, array $payload): self
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        return new self($status, $body === false ? '{"ok":false}' : $body, ['Content-Type' => 'application/json; charset=utf-8']);
    }

    public static function binary(string $body, string $mime, ?string $downloadAs = null): self
    {
        $disp = $downloadAs === null ? 'inline' : 'attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $downloadAs) . '"';
        return new self(200, $body, ['Content-Type' => $mime, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, max-age=300', 'Content-Disposition' => $disp]);
    }

    public static function csv(string $body, string $filename): self
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename);
        return new self(200, $body, ['Content-Type' => 'text/csv; charset=utf-8', 'Content-Disposition' => 'attachment; filename="' . $safe . '"']);
    }

    public function withHeader(string $k, string $v): self
    {
        $this->headers[$k] = $v;
        return $this;
    }

    /** Merge request id into meta so every response is traceable. */
    public function withRequestId(string $id): self
    {
        $d = json_decode($this->body, true);
        if (is_array($d)) {
            $meta = isset($d['meta']) && is_array($d['meta']) ? $d['meta'] : [];
            $meta['request_id'] = $id;
            $d['meta'] = $meta;
            $this->body = (string) json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        }
        $this->headers['X-Request-Id'] = $id;
        return $this;
    }

    public function send(): void
    {
        http_response_code($this->status);
        header_remove('X-Powered-By');       // do not advertise the PHP version
        foreach ($this->headers as $k => $v) {
            header($k . ': ' . $v);
        }
        echo $this->body;
    }
}
