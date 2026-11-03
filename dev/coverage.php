<?php

declare(strict_types=1);

/**
 * Line coverage of src/ by the integration suites. dev/dev.sh prepends this to every PHP process they run (the console
 * scripts, the dev server's requests, the queue) via auto_prepend_file when COVERAGE=1; each process or request writes
 * what it executed to storage/coverage/*.cov, and dev/coverage-report.php merges them into one report.
 * Needs the pcov extension and phpunit/php-code-coverage in the dev project (dev.sh installs the package).
 * Env: COVERAGE_PROJECT_DIR (the dev project).
 */

use SebastianBergmann\CodeCoverage\CodeCoverage;
use SebastianBergmann\CodeCoverage\Driver\Selector;
use SebastianBergmann\CodeCoverage\Filter;
use SebastianBergmann\CodeCoverage\Report\PHP as PhpReport;

$projectDir = getenv('COVERAGE_PROJECT_DIR') ?: throw new RuntimeException('COVERAGE_PROJECT_DIR is not set');
require $projectDir . '/vendor/autoload.php';

$filter = new Filter();
$src = dirname(__DIR__) . '/src';
$filter->includeFiles(array_values(array_filter(
    array_map(fn(SplFileInfo $file): string => $file->getPathname(), iterator_to_array(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS)))),
    // config.php is the template users copy into config/, never run by the plugin; translations are arrays of strings
    fn(string $path): bool => str_ends_with($path, '.php') && $path !== $src . '/config.php' && !str_contains($path, '/translations/'),
)));
$coverage = new CodeCoverage((new Selector())->forLineCoverage($filter), $filter);
$coverage->start('integration');
register_shutdown_function(static function () use ($coverage, $projectDir): void {
    $coverage->stop();
    $dir = $projectDir . '/storage/coverage';
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    (new PhpReport())->process($coverage, $dir . '/' . uniqid(getmypid() . '-', true) . '.cov');
});
