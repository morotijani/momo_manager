<?php

declare(strict_types=1);

namespace App\Core;

final class Autoloader
{
    public static function register(string $rootPath): void
    {
        require_once $rootPath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'config.php';

        spl_autoload_register(static function (string $class) use ($rootPath): void {
            if (str_starts_with($class, 'App\\')) {
                $relative = substr($class, 4);
                $file = $rootPath . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR
                    . str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';
                if (is_file($file)) {
                    require_once $file;
                }
                return;
            }

            if (str_starts_with($class, 'Database\\')) {
                $relative = substr($class, 9);
                $file = $rootPath . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR
                    . str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';
                if (is_file($file)) {
                    require_once $file;
                }
            }
        });
    }
}
