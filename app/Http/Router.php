<?php

declare(strict_types=1);

namespace App\Http;

use Closure;
use Throwable;

final class Router
{
    /** @var array<string, array<string, Closure|array{0:string, 1:string}>> */
    private array $routes = [];

    public function get(string $path, Closure|array $handler): self
    {
        return $this->add('GET', $path, $handler);
    }

    public function post(string $path, Closure|array $handler): self
    {
        return $this->add('POST', $path, $handler);
    }

    public function delete(string $path, Closure|array $handler): self
    {
        return $this->add('DELETE', $path, $handler);
    }

    public function add(string $method, string $path, Closure|array $handler): self
    {
        $this->routes[strtoupper($method)][$path] = $handler;
        return $this;
    }

    public function dispatch(): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

        // Handle CORS preflight
        if ($method === 'OPTIONS') {
            $this->cors();
            http_response_code(204);
            exit;
        }

        $this->cors();

        $routesForMethod = $this->routes[$method] ?? [];

        // Exact match
        if (isset($routesForMethod[$uri])) {
            $this->execute($routesForMethod[$uri], []);
            return;
        }

        // Pattern matching with parameters (e.g. /api/media/{id})
        foreach ($routesForMethod as $routePattern => $handler) {
            $pattern = preg_replace('/\{([a-zA-Z0-9_]+)\}/', '(?P<$1>[^/]+)', $routePattern);
            $pattern = '#^' . $pattern . '$#';

            if (preg_match($pattern, $uri, $matches)) {
                $params = array_filter($matches, fn ($k) => ! is_numeric($k), ARRAY_FILTER_USE_KEY);
                $this->execute($handler, $params);
                return;
            }
        }

        // 404 Not Found
        if (str_starts_with($uri, '/api/')) {
            $this->json(['error' => 'Endpoint no encontrado'], 404);
        } else {
            http_response_code(404);
            echo '404 - Página no encontrada';
        }
    }

    private function execute(Closure|array $handler, array $params): void
    {
        try {
            if ($handler instanceof Closure) {
                $response = $handler($params);
            } elseif (is_array($handler)) {
                [$class, $method] = $handler;
                $instance = new $class();
                $response = $instance->$method($params);
            } else {
                throw new \InvalidArgumentException('Handler de ruta inválido');
            }

            if (is_array($response)) {
                $this->json($response);
            }
        } catch (Throwable $e) {
            $this->json([
                'error' => $e->getMessage(),
                'file' => basename($e->getFile()) . ':' . $e->getLine(),
            ], 500);
        }
    }

    public function json(mixed $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    public static function body(): array
    {
        $raw = file_get_contents('php://input');
        if (! $raw) {
            return $_POST;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? array_merge($_POST, $decoded) : $_POST;
    }

    public static function query(string $key, mixed $default = null): mixed
    {
        return $_GET[$key] ?? $default;
    }

    private function cors(): void
    {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
    }
}
