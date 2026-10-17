<?php
declare(strict_types=1);

$GLOBALS['tests'] = 0;
$GLOBALS['failed'] = 0;

function same(mixed $expected, mixed $actual, string $label): void
{
    $GLOBALS['tests']++;
    if ($expected === $actual) {
        echo "PASS $label\n";
        return;
    }
    $GLOBALS['failed']++;
    echo "FAIL $label\n  mong đợi: " . var_export($expected, true) . "\n  thực tế:  " . var_export($actual, true) . "\n";
}

function finish(): never
{
    printf("TESTS=%d FAILED=%d\n", $GLOBALS['tests'], $GLOBALS['failed']);
    exit($GLOBALS['failed'] === 0 ? 0 : 1);
}
