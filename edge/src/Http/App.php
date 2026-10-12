<?php

declare(strict_types=1);

namespace Nico\Edge\Http;

/**
 * Front controller (single entry point).
 *
 * cPanel shared hosting serves PHP through Apache, so all requests are routed
 * here. Routing is an explicit match rather than a dispatcher framework, keeping
 * the dependency count at zero for FTP upload.
 */
final class App
{
    private array $routes = [];

    public function get(string $path, callable $handler): void
    {
        $this->routes['GET'][$path] = $handler;
    }

    public function post(string $path, callable $handler): void
    {
        $this->routes['POST'][$path] = $handler;
    }

    public function run(): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $path = $this->resolvePath();

        if (!isset($this->routes[$method][$path])) {
            $this->notFound();

            return;
        }

        try {
            ($this->routes[$method][$path])($this);
        } catch (\Throwable $e) {
            $this->serverError($e);
        }
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
