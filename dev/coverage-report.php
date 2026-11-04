<?php

declare(strict_types=1);

/**
 * Merges the coverage the integration suites wrote (dev/coverage.php) and prints it: the total, the files below the
 * bar, and an HTML report in storage/coverage/html. Exits 1 when src/ is below COVERAGE_MIN percent (default 100).
 * Run: php dev/coverage-report.php <craft-project-dir>
 */

use SebastianBergmann\CodeCoverage\CodeCoverage;
use SebastianBergmann\CodeCoverage\Node\File;
use SebastianBergmann\CodeCoverage\Report\Html\Facade as HtmlReport;

$projectDir = $argv[1] ?? throw new RuntimeException('Usage: php dev/coverage-report.php <craft-project-dir>');
require $projectDir . '/vendor/autoload.php';
$dir = $projectDir . '/storage/coverage';
$min = (float)(getenv('COVERAGE_MIN') ?: 100);

$merged = null;
foreach (glob($dir . '/*.cov') ?: [] as $file) {
    /** @var CodeCoverage $part */
    $part = include $file;
    $merged === null ? $merged = $part : $merged->merge($part);
}
if (!$merged instanceof CodeCoverage) {
    fwrite(STDERR, "No coverage in $dir (run the suites with COVERAGE=1)\n");
    exit(1);
}

$report = $merged->getReport();
$files = [];
foreach ($report as $node) { // Directory's iterator walks every node below it
    if ($node instanceof File) {
        $files[] = [$node->percentageOfExecutedLines()->asFloat(), $node->numberOfExecutedLines(), $node->numberOfExecutableLines(), substr($node->pathAsString(), strlen($report->pathAsString()) + 1)];
    }
}
usort($files, fn(array $a, array $b): int => $a[0] <=> $b[0]);
$total = $report->percentageOfExecutedLines()->asFloat();
printf("Coverage of src/: %.1f%% of lines (%d of %d), %d files; bar: %.0f%%\n", $total, $report->numberOfExecutedLines(), $report->numberOfExecutableLines(), count($files), $min);
// the lines no test ran, by file (CI logs show them without the HTML report)
$missing = [];
foreach ($merged->getData()->lineCoverage() as $file => $lines) {
    $missing[$file] = array_keys(array_filter($lines, fn($tests): bool => $tests === []));
}
foreach ($files as [$pct, $done, $all, $path]) {
    if ($pct < $min) {
        $lines = current(array_filter($missing, fn(string $file): bool => str_ends_with($file, '/' . $path), ARRAY_FILTER_USE_KEY)) ?: [];
        printf("  %5.1f%%  %4d/%-4d  %s%s\n", $pct, $done, $all, $path, $lines ? '  lines ' . implode(', ', $lines) : '');
    }
}
(new HtmlReport())->process($merged, $dir . '/html');
echo "HTML: $dir/html/index.html\n";
exit($total >= $min ? 0 : 1);
