<?php

declare(strict_types=1);

namespace App\Core;

defined('APP_SECURE_BOOT') or exit;

final class Router
{
    /** @var array<string, array<string, string>> */
    private array $routes = [
        'GET'  => [],
        'POST' => [],
    ];

    public function get(string $path, string $handler): void
    {
        $this->routes['GET'][$path] = $handler;
    }

    public function post(string $path, string $handler): void
    {
        $this->routes['POST'][$path] = $handler;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function dispatch(string $method, string $uri, array $context = []): void
    {
        $lookupMethod = strtoupper($method) === 'HEAD' ? 'GET' : strtoupper($method);
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $path = $path !== '/' ? rtrim($path, '/') : '/';

        foreach ($this->routes[$lookupMethod] ?? [] as $route => $handler) {
            $quoted  = preg_quote($route, '#');
            $pattern = '#^' . preg_replace('/\\\{([a-zA-Z0-9_]+)\\\}/', '(?P<$1>[^/]+)', $quoted) . '$#';

            if (preg_match($pattern, $path, $matches)) {
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
                [$controllerClass, $action] = explode('@', $handler);

                $controller = new $controllerClass($context);
                $controller->$action(...array_values($params));
                return;
            }
        }

        http_response_code(404);
        echo '<!DOCTYPE html><html lang="en" dir="ltr"><head><meta charset="UTF-8"><title>404 - Page not found</title></head><body style="background:#0f172a;color:#f8fafc;font-family:sans-serif;text-align:center;padding:80px"><h1>404 - Page not found</h1><a href="/" style="color:#38bdf8">Back to home</a></body></html>';
    }
}
