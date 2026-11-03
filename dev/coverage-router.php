<?php

declare(strict_types=1);

/**
 * The dev server's router under COVERAGE=1. PHP's built-in server runs no auto_prepend_file for its router script, so
 * every control panel page (`/admin/…`, routed into Craft's index.php by Craft's router) would go unmeasured; only
 * files it serves itself (`/index.php?p=…`) get dev/coverage.php prepended. Files are left to the server as Craft's
 * router does; everything else starts the coverage first.
 */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
if ($path && realpath($_SERVER['DOCUMENT_ROOT'] . DIRECTORY_SEPARATOR . urldecode($path))) {
    return false;
}
require __DIR__ . '/coverage.php';
return require getenv('COVERAGE_PROJECT_DIR') . '/vendor/craftcms/cms/bootstrap/router.php';
