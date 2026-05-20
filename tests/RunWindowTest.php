<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/RunWindow.php';

$timezone = new DateTimeZone('Asia/Ho_Chi_Minh');

assertSame(false, RunWindow::isAllowed(new DateTimeImmutable('2026-05-20 08:59:59', $timezone), true, 9, 23));
assertSame(true, RunWindow::isAllowed(new DateTimeImmutable('2026-05-20 09:00:00', $timezone), true, 9, 23));
assertSame(true, RunWindow::isAllowed(new DateTimeImmutable('2026-05-20 23:59:59', $timezone), true, 9, 23));
assertSame(false, RunWindow::isAllowed(new DateTimeImmutable('2026-05-20 00:00:00', $timezone), true, 9, 23));
assertSame(true, RunWindow::isAllowed(new DateTimeImmutable('2026-05-20 00:00:00', $timezone), false, 9, 23));

echo "RunWindow test passed\n";

function assertSame(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException('Assertion failed: expected=' . var_export($expected, true) . ', actual=' . var_export($actual, true));
    }
}
