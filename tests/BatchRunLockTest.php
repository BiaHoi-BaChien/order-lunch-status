<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/BatchRunLock.php';

$path = sys_get_temp_dir() . '/order-lunch-status-batch-lock-test.lock';
$first = BatchRunLock::acquire($path);
assertTrue($first instanceof BatchRunLock);
assertSame(null, BatchRunLock::acquire($path));
$first->release();
$second = BatchRunLock::acquire($path);
assertTrue($second instanceof BatchRunLock);
$second->release();

echo "BatchRunLock test passed\n";

function assertTrue(bool $actual): void
{
    if (!$actual) {
        throw new RuntimeException('Assertion failed: expected true');
    }
}

function assertSame(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException('Assertion failed: values differ');
    }
}
