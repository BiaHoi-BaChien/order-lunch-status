<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/LunchOrderService.php';

$service = (new ReflectionClass(LunchOrderService::class))->newInstanceWithoutConstructor();
$turnPath = sys_get_temp_dir() . '/order-lunch-status-receipt-turn-' . bin2hex(random_bytes(8)) . '.state';
register_shutdown_function(static fn () => is_file($turnPath) && unlink($turnPath));
(new ReflectionProperty(LunchOrderService::class, 'config'))->setValue($service, [
    'gmail_receipt_turn_path' => $turnPath,
]);
$mailSearchLimits = new ReflectionMethod(LunchOrderService::class, 'mailSearchLimits');

// 少ない検索枠でも3店舗すべてに順番が回る。
assertSame([0, 0, 0], $mailSearchLimits->invoke($service, 0));
assertSame([0, 1, 0], $mailSearchLimits->invoke($service, 1));
assertSame([0, 0, 1], $mailSearchLimits->invoke($service, 1));
assertSame([1, 0, 0], $mailSearchLimits->invoke($service, 1));
assertSame([0, 1, 1], $mailSearchLimits->invoke($service, 2));
assertSame([1, 1, 0], $mailSearchLimits->invoke($service, 2));
assertSame([1, 0, 1], $mailSearchLimits->invoke($service, 2));
assertSame([1, 1, 1], $mailSearchLimits->invoke($service, 3));

foreach ([4, 5, 100, 1000] as $budget) {
    $totals = [0, 0, 0];
    for ($run = 0; $run < 3; $run++) {
        $limits = $mailSearchLimits->invoke($service, $budget);
        assertSame($budget, array_sum($limits));
        assertSame(1, max($limits) - min($limits));
        foreach ($limits as $index => $limit) {
            $totals[$index] += $limit;
        }
    }
    assertSame([$budget, $budget, $budget], $totals);
}

// 旧バージョンで保存した次回店舗を引き継ぐ。
foreach (['matsuya' => [1, 0, 0], 'kimura' => [0, 1, 0]] as $next => $expected) {
    file_put_contents($turnPath, $next);
    assertSame($expected, $mailSearchLimits->invoke($service, 1));
}

echo "LunchOrderService receipt test passed\n";

function assertSame(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException('Assertion failed: expected=' . var_export($expected, true) . ', actual=' . var_export($actual, true));
    }
}
