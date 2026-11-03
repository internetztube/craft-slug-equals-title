<?php

declare(strict_types=1);

/**
 * The dev server's router under COVERAGE=1. PHP's built-in server runs no auto_prepend_file for its router script, so
 * every control panel page (routed into public/index.php by Laravel's router) would go unmeasured. Files are left to
 * the server as Laravel's router does; everything else starts the coverage first.
 */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
if ($path && $path !== '/' && is_file(getcwd() . urldecode($path))) {
    return false;
}
require __DIR__ . '/coverage.php';
return require getenv('COVERAGE_PROJECT_DIR') . '/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php';
