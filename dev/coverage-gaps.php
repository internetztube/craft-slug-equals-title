<?php

declare(strict_types=1);

/**
 * The lines the integration suites never ran (dev/coverage.php data), per file with the code, most first: where tests
 * are missing. Run: php dev/coverage-gaps.php <craft-project-dir> [path filter]
 */

use SebastianBergmann\CodeCoverage\CodeCoverage;

$projectDir = $argv[1] ?? throw new RuntimeException('Usage: php dev/coverage-gaps.php <craft-project-dir> [filter]');
$filter = $argv[2] ?? '';
require $projectDir . '/vendor/autoload.php';
$merged = null;
foreach (glob($projectDir . '/storage/coverage/*.cov') ?: [] as $file) {
    /** @var CodeCoverage $part */
    $part = include $file;
    $merged === null ? $merged = $part : $merged->merge($part);
}
if (!$merged instanceof CodeCoverage) {
    exit("No coverage data\n");
}
$src = dirname(__DIR__) . '/src/';
$gaps = [];
foreach ($merged->getData()->lineCoverage() as $file => $lines) {
    if ($filter !== '' && !str_contains($file, $filter)) {
        continue;
    }
    $missing = array_keys(array_filter($lines, fn($tests): bool => is_array($tests) && $tests === []));
    if ($missing) {
        $gaps[$file] = $missing;
    }
}
uasort($gaps, fn(array $a, array $b): int => count($b) <=> count($a));
foreach ($gaps as $file => $missing) {
    $code = file($file) ?: [];
    echo '== ' . str_replace($src, '', $file) . ' (' . count($missing) . ")\n";
    foreach ($missing as $line) {
        echo sprintf("%5d  %s\n", $line, rtrim($code[$line - 1] ?? ''));
    }
}
