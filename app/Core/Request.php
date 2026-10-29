<?php

declare(strict_types=1);

namespace App\Core;

final class Request
{
    private string $method;
    private string $path;
    private string $baseUrl;
    private array $query;
    private array $body;
    private array $headers;
    private array $params = [];
    private string $ip;

    public function __construct(
        ?string $method = null,
        ?string $path = null,
        ?array $query = null,
        ?array $body = null,
        ?array $headers = null,
        ?string $ip = null
    ) {
        $this->method = strtoupper($method ?? ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $this->query = $query ?? $_GET;
        $this->headers = $headers !== null ? array_change_key_case($headers, CASE_LOWER) : self::extractHeaders();
        $this->ip = $ip ?? ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');

        $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/index.php');
        $baseDir = rtrim(dirname($scriptName), '/');
        $this->baseUrl = ($baseDir === '' || $baseDir === '.') ? '' : $baseDir;

        if ($path !== null) {
            $this->path = '/' . trim($path, '/');
            if ($this->path !== '/') {
                $this->path = rtrim($this->path, '/');
            }
        } else {
            $this->path = $this->resolvePath();
        }

        if ($body !== null) {
            $this->body = $body;
        } else {
            $this->body = $this->parseBody();
        }
    }

    private function resolvePath(): string
    {
        if (!empty($this->query['route']) && is_string($this->query['route'])) {
            $p = '/' . ltrim($this->query['route'], '/');
            return $p === '/' ? '/' : rtrim($p, '/');
        }

        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        if ($this->baseUrl !== '' && str_starts_with($uri, $this->baseUrl)) {
            $uri = substr($uri, strlen($this->baseUrl)) ?: '/';
        }
        if (str_starts_with($uri, '/index.php')) {
            $uri = substr($uri, 10) ?: '/';
        }

        $normalized = '/' . ltrim($uri, '/');
        return $normalized === '/' ? '/' : rtrim($normalized, '/');
    }

    private function parseBody(): array
    {
        $contentType = $this->header('content-type', '');
        $raw = file_get_contents('php://input');
        if ($raw !== false && $raw !== '' && (str_contains($contentType, 'application/json') || str_starts_with(trim($raw), '{'))) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return $_POST;
    }

    private static function extractHeaders(): array
    {
        $headers = [];
        if (function_exists('getallheaders')) {
            foreach (getallheaders() as $k => $v) {
                $headers[strtolower((string) $k)] = (string) $v;
            }
        }
        foreach ($_SERVER as $key => $val) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = (string) $val;
            } elseif (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH', 'AUTHORIZATION'], true)) {
                $name = strtolower(str_replace('_', '-', $key));
                $headers[$name] = (string) $val;
            }
        }
        return $headers;
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    public function ip(): string
    {
        return $this->ip;
    }

    public function header(string $name, string $default = ''): string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    public function allQuery(): array
    {
        return $this->query;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $default;
    }

    public function string(string $key, string $default = ''): string
    {
        $val = $this->body[$key] ?? $default;
        return is_scalar($val) ? trim((string) $val) : $default;
    }

    public function float(string $key, float $default = 0.0): float
    {
        $val = $this->body[$key] ?? null;
        if ($val === null || $val === '') {
            return $default;
        }
        $cleaned = str_replace([',', 'GHS', 'GH₵', ' '], '', (string) $val);
        return is_numeric($cleaned) ? round((float) $cleaned, 2) : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $val = $this->body[$key] ?? null;
        return is_numeric($val) ? (int) $val : $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        if (!array_key_exists($key, $this->body)) {
            return $default;
        }
        $val = $this->body[$key];
        if (is_bool($val)) {
            return $val;
        }
        return in_array(strtolower((string) $val), ['1', 'true', 'yes', 'on'], true);
    }

    public function all(): array
    {
        return $this->body;
    }

    public function setParams(array $params): void
    {
        $this->params = $params;
    }

    public function param(string $key, ?string $default = null): ?string
    {
        return $this->params[$key] ?? $default;
    }
}
