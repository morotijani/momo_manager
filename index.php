<?php

declare(strict_types=1);

use App\Core\Bootstrap;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Security;

$rootPath = __DIR__;

// Support PHP built-in server static files if tested via `php -S`
if (PHP_SAPI === 'cli-server') {
    $uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $candidate = $rootPath . str_replace('/', DIRECTORY_SEPARATOR, $uriPath);
    if ($uriPath !== '/' && is_file($candidate)) {
        return false;
    }
}

require_once $rootPath . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Core' . DIRECTORY_SEPARATOR . 'Bootstrap.php';
Bootstrap::init($rootPath);
$request = new Request();

// Verify database connectivity (auto-creates database & schema on XAMPP first visit)
$dbError = null;
try {
    Database::connection();
} catch (\Throwable $e) {
    $dbError = $e->getMessage();
    if (str_starts_with($request->path(), '/api/')) {
        Response::error(
            'Database connection unavailable. Please ensure MySQL is running in XAMPP Control Panel. (' . $dbError . ')',
            503
        );
    }
}

if ($dbError === null && str_starts_with($request->path(), '/api/')) {
    $router = Bootstrap::createRouter();
    $router->dispatch($request);
    exit;
}

$csrfToken = Security::csrfToken();
$baseUrl = $request->baseUrl();

require $rootPath . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'app.php';
