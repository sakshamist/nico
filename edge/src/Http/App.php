<?php

declare(strict_types=1);

namespace Nico\Edge\Http;

/**
 * Front controller (single entry point).
 *
 * cPanel shared hosting serves PHP through Apache, so all requests are routed
 * here. Routing is an explicit match rather than a dispatcher framework, keeping
 * the dependency count at zero for FTP upload.
 *
 * Routes may contain named placeholders (`/worker/jobs/{id}/heartbeat`); the
 * captured values are passed to the handler as an associative array. Handlers
 * that do not declare the second parameter simply ignore it.
 */
final class App
{
    /** @var array<string,array<string,callable>> */
    private array $routes = [];

    public function get(string $path, callable $handler): void
    {
        $this->routes['GET'][$path] = $handler;
    }

    public function post(string $path, callable $handler): void
    {
        $this->routes['POST'][$path] = $handler;
    }

    public function put(string $path, callable $handler): void
    {
        $this->routes['PUT'][$path] = $handler;
    }

    public function patch(string $path, callable $handler): void
    {
        $this->routes['PATCH'][$path] = $handler;
    }

    public function delete(string $path, callable $handler): void
    {
        $this->routes['DELETE'][$path] = $handler;
    }

    public function run(): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $path = $this->resolvePath();
        $routes = $this->routes[$method] ?? [];

        $handler = $routes[$path] ?? null;
        $params = [];

        if ($handler === null) {
            foreach ($routes as $pattern => $candidate) {
                if (!str_contains($pattern, '{')) {
                    continue;
                }

                if (preg_match($this->compile($pattern), $path, $matches) === 1) {
                    $handler = $candidate;

                    foreach ($matches as $key => $value) {
                        if (is_string($key)) {
                            $params[$key] = $value;
                        }
                    }

                    break;
                }
            }
        }

        if ($handler === null) {
            $this->notFound();

            return;
        }

        try {
            $handler($this, $params);
        } catch (\Throwable $e) {
            $this->serverError($e);
        }
    }

    /** Compiles `/jobs/{id}` into a named-capture regex. */
    private function compile(string $pattern): string
    {
        $quoted = preg_quote($pattern, '#');

        $regex = preg_replace_callback(
            '/\\\\\{([a-zA-Z_][a-zA-Z0-9_]*)\\\\\}/',
            static fn (array $m): string => '(?P<' . $m[1] . '>[^/]+)',
            $quoted,
        );

        return '#^' . ($regex ?? $quoted) . '$#';
    }

    private function resolvePath(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';

        // Support hosting that mounts the app in a subdirectory.
        $scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
        if ($scriptDir !== '' && $scriptDir !== '/' && str_starts_with($path, $scriptDir)) {
            $path = substr($path, strlen($scriptDir));
        }

        $path = '/' . trim($path, '/');

        return $path === '/' ? '/' : rtrim($path, '/');
    }

    private function notFound(): void
    {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'not_found', 'path' => $_SERVER['REQUEST_URI'] ?? '']);
    }

    private function serverError(\Throwable $e): void
    {
        // The message may contain internals; the detail goes to the log only.
        \Nico\Edge\Support\Log::error('http.unhandled_exception', [
            'type'  => $e::class,
            'where' => $e->getFile() . ':' . $e->getLine(),
            'msg'   => $e->getMessage(),
        ]);

        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'internal_error']);
    }
}
