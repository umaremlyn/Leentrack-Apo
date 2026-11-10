<?php
declare(strict_types=1);

namespace LeenTrack\Core;

/** Tiny pattern router: /products/{id}. Params are restricted to [A-Za-z0-9_-]+ (UUID-safe). */
final class Router
{
    /** @var list<array{method:string,regex:string,handler:callable,middleware:list<string>,pattern:string,public:bool}> */
    private array $routes = [];

    /** @param list<string> $middleware */
    public function add(string $method, string $pattern, callable $handler, array $middleware = [], bool $public = false): void
    {
        $regex = '#^' . preg_replace('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', '(?P<$1>[A-Za-z0-9_-]+)', $pattern) . '$#';
        $this->routes[] = compact('method', 'regex', 'handler', 'middleware', 'pattern', 'public');
    }

    /** @param list<string> $middleware */
    public function get(string $p, callable $h, array $mw = [], bool $public = false): void { $this->add('GET', $p, $h, $mw, $public); }
    /** @param list<string> $middleware */
    public function post(string $p, callable $h, array $mw = [], bool $public = false): void { $this->add('POST', $p, $h, $mw, $public); }
    public function patch(string $p, callable $h, array $mw = []): void { $this->add('PATCH', $p, $h, $mw); }
    public function put(string $p, callable $h, array $mw = []): void { $this->add('PUT', $p, $h, $mw); }
    public function delete(string $p, callable $h, array $mw = []): void { $this->add('DELETE', $p, $h, $mw); }

    /**
     * @return array{handler:callable,params:array<string,string>,middleware:list<string>,pattern:string,public:bool}
     */
    public function match(string $method, string $path): array
    {
        $allowed = [];
        foreach ($this->routes as $r) {
            if (preg_match($r['regex'], $path, $m)) {
                if ($r['method'] === $method) {
                    $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
                    return ['handler' => $r['handler'], 'params' => $params, 'middleware' => $r['middleware'], 'pattern' => $r['pattern'], 'public' => $r['public']];
                }
                $allowed[] = $r['method'];
            }
        }
        if ($allowed) {
            $allowed[] = 'OPTIONS';
            throw ApiException::methodNotAllowed(array_values(array_unique($allowed)));
        }
        throw ApiException::notFound('Route not found.');
    }

    /** @return list<array{method:string,pattern:string,public:bool}> for the route-table-driven security tests */
    public function table(): array
    {
        return array_map(fn ($r) => ['method' => $r['method'], 'pattern' => $r['pattern'], 'public' => $r['public']], $this->routes);
    }
}
