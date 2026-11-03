<?php

declare(strict_types=1);

/**
 * Boots the dev project's Craft 6 app (a Laravel app) for a script run outside Artisan: `require __DIR__ . '/craft.php';`
 * with the project directory as the script's first argument.
 */

$projectDir = $argv[1] ?? throw new RuntimeException('Usage: php ' . ($argv[0] ?? 'script.php') . ' <craft-project-dir>');
require $projectDir . '/vendor/autoload.php';
/** @var Illuminate\Foundation\Application $app */
$app = require $projectDir . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
// an uncaught exception fails the script: the Yii2 adapter's error handler (registered while booting) would exit with 0
set_exception_handler(static function (Throwable $e): never {
    fwrite(STDERR, $e . "\n");
    exit(1);
});
