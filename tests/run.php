<?php
declare(strict_types=1);

/**
 * Real Simple RSS test runner.
 *
 * Usage: php tests/run.php   (or: composer test)
 * Exits 0 when everything passes, 1 otherwise.
 */

require __DIR__ . '/helpers.php';

echo 'Real Simple RSS - test suite (PHP ' . PHP_VERSION . ")\n\n";

$files = glob(__DIR__ . '/*_test.php') ?: [];
sort($files);
if (!$files) {
    echo "No test files found.\n";
    exit(1);
}

foreach ($files as $file) {
    echo basename($file) . "\n";
    try {
        require $file;
    } catch (Throwable $e) {
        test_failed('uncaught ' . get_class($e) . ': ' . $e->getMessage());
    }
    echo "\n";
}

$pass = $GLOBALS['rsrss_pass'];
$fail = $GLOBALS['rsrss_fail'];
printf("%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
