<?php

declare(strict_types=1);

namespace App\Core;

final class Router
{
    /** @var list<array{method:string, pattern:string, regex:string, handler:callable|array{0:class-string,1:string}}> */
    private array $routes = [];

    public function add(string $method, string $pattern, callable|array $handler): void
    {
        $normalized = '/' . trim($pattern, '/');
        if ($normalized !== '/') {
            $normalized = rtrim($normalized, '/');
        }

        $regex = preg_replace_callback('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', static function (array $m): string {
            return '(?P<' . $m[1] . '>[^/]+)';
        }, $normalized);

        $this->routes[] = [
            'method' => strtoupper($method),
            'pattern' => $normalized,
            'regex' => '#^' . $regex . '$#',
            'handler' => $handler,
        ];
    }

    public function get(string $pattern, callable|array $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, callable|array $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    public function put(string $pattern, callable|array $handler): void
    {
        $this->add('PUT', $pattern, $handler);
    }

    public function delete(string $pattern, callable|array $handler): void
    {
        $this->add('DELETE', $pattern, $handler);
    }

    public function dispatch(Request $request): mixed
    {
        $method = $request->method();
        $path = $request->path();

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }
            if (preg_match($route['regex'], $path, $matches)) {
                $params = [];
                foreach ($matches as $k => $v) {
                    if (is_string($k)) {
                        $params[$k] = urldecode($v);
                    }
                }
                $request->setParams($params);

                $handler = $route['handler'];
                if (is_array($handler)) {
                    [$class, $action] = $handler;
                    $instance = new $class();
                    return $instance->{$action}($request);
                }
                return $handler($request);
            }
        }

        if (str_starts_with($path, '/api/')) {
            Response::error('API endpoint not found: ' . $method . ' ' . $path, 404);
        }

        return null;
    }
}
