<?php
declare(strict_types=1);

namespace LeenTrack\Core;

final class Request
{
    public const MAX_BODY = 2_097_152;   // hard cap on any body (2 MB)
    public const MAX_JSON = 1_048_576;   // JSON bodies are capped at 1 MB

    /** @var array<string,mixed>|null */
    private ?array $json = null;
    /** @var array<string,mixed> route params */
    public array $params = [];
    /** @var array<string,mixed> request-scoped attributes set by middleware (user, business...) */
    public array $attributes = [];
    public string $id;

    /**
     * @param array<string,string> $headers lower-cased names
     * @param array<string,mixed> $query
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $headers,
        private readonly string $rawBody,
        public readonly string $ip,
    ) {
        $this->id = Uuid::v4();
    }

    /** @param array<string,mixed> $server */
    public static function fromGlobals(array $server, string $body, array $trustedProxies = []): self
    {
        $headers = [];
        foreach ($server as $k => $v) {
            if (str_starts_with((string) $k, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr((string) $k, 5)))] = (string) $v;
            }
        }
        if (isset($server['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $server['CONTENT_TYPE'];
        }
        if (isset($server['CONTENT_LENGTH'])) {
            $headers['content-length'] = (string) $server['CONTENT_LENGTH'];
        }
        $uri = (string) ($server['REQUEST_URI'] ?? '/');
        $path = '/' . trim((string) parse_url($uri, PHP_URL_PATH), '/');
        $query = [];
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);

        $ip = (string) ($server['REMOTE_ADDR'] ?? '0.0.0.0');
        // X-Forwarded-For is honoured ONLY when the direct peer is a configured trusted proxy.
        if (in_array($ip, $trustedProxies, true) && isset($headers['x-forwarded-for'])) {
            $first = trim(explode(',', $headers['x-forwarded-for'])[0]);
            if (filter_var($first, FILTER_VALIDATE_IP)) {
                $ip = $first;
            }
        }
        return new self(strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET')), $path, $query, $headers, $body, $ip);
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    public function bearerToken(): ?string
    {
        $h = $this->header('authorization');
        if ($h !== null && preg_match('/^Bearer\s+([A-Za-z0-9._~+\/=-]{16,512})$/', $h, $m)) {
            return $m[1];
        }
        return null;
    }

    /** @return array<string,mixed> */
    public function json(): array
    {
        if ($this->json !== null) {
            return $this->json;
        }
        if ($this->rawBody === '') {
            return $this->json = [];
        }
        if (strlen($this->rawBody) > self::MAX_JSON) {
            throw new ApiException('payload_too_large', 'Request body is too large.', 413);
        }
        $ct = strtolower((string) $this->header('content-type', ''));
        if (!str_starts_with($ct, 'application/json')) {
            throw new ApiException('unsupported_media_type', 'Content-Type must be application/json.', 415);
        }
        try {
            $data = json_decode($this->rawBody, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ApiException('invalid_json', 'Request body is not valid JSON.', 400);
        }
        if (!is_array($data) || array_is_list($data) && $data !== []) {
            throw new ApiException('invalid_json', 'Request body must be a JSON object.', 400);
        }
        return $this->json = $data;
    }

    /** Raw CSV text from a text/csv body (or JSON {"csv": "..."}); max 2 MB, UTF-8 only. */
    public function csvText(): string
    {
        $ct = strtolower((string) $this->header('content-type', ''));
        if (strlen($this->rawBody) > self::MAX_BODY) {
            throw new ApiException('payload_too_large', 'File is too large (2 MB maximum).', 413);
        }
        if (str_starts_with($ct, 'text/csv') || str_starts_with($ct, 'text/plain')) {
            $text = $this->rawBody;
        } elseif (str_starts_with($ct, 'application/json')) {
            $d = json_decode($this->rawBody, true);
            $text = is_array($d) && is_string($d['csv'] ?? null) ? $d['csv'] : '';
        } else {
            throw new ApiException('unsupported_media_type', 'Send the file as text/csv.', 415);
        }
        if (!mb_check_encoding($text, 'UTF-8')) {
            throw new ApiException('invalid_encoding', 'The file must be UTF-8 encoded.', 422);
        }
        return $text;
    }

    public function auth(): \LeenTrack\Http\AuthContext
    {
        $a = $this->attributes['auth'] ?? null;
        if (!$a instanceof \LeenTrack\Http\AuthContext) { throw ApiException::unauthenticated(); }
        return $a;
    }

    public function tenant(): \LeenTrack\Http\TenantContext
    {
        $t = $this->attributes['tenant'] ?? null;
        if (!$t instanceof \LeenTrack\Http\TenantContext) { throw ApiException::forbidden('Business context required.'); }
        return $t;
    }

    public function rawBody(): string
    {
        return $this->rawBody;
    }
}
