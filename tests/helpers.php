<?php
declare(strict_types=1);

/**
 * Real Simple RSS test helpers. Plain assertions over the pure functions in
 * lib.php - no framework, no database, no network required.
 */

error_reporting(E_ALL);
require_once __DIR__ . '/../lib.php';

$GLOBALS['rsrss_pass'] = 0;
$GLOBALS['rsrss_fail'] = 0;

function check(string $label, $expected, $actual): void
{
    if ($expected === $actual) {
        $GLOBALS['rsrss_pass']++;
        echo "  ok    $label\n";
        return;
    }
    $GLOBALS['rsrss_fail']++;
    echo "  FAIL  $label\n";
    echo '        expected: ' . test_repr($expected) . "\n";
    echo '        actual:   ' . test_repr($actual) . "\n";
}

function check_contains(string $label, string $haystack, string $needle): void
{
    check($label, true, strpos($haystack, $needle) !== false);
}

function check_not_contains(string $label, string $haystack, string $needle): void
{
    check($label, false, strpos($haystack, $needle) !== false);
}

function check_null(string $label, $actual): void
{
    check($label, null, $actual);
}

function test_failed(string $message): void
{
    $GLOBALS['rsrss_fail']++;
    echo "  FAIL  $message\n";
}

function test_repr($value): string
{
    if ($value === null) {
        return 'null';
    }
    if ($value === true) {
        return 'true';
    }
    if ($value === false) {
        return 'false';
    }
    return var_export($value, true);
}
