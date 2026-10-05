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

// 松屋とKIMURAの単一メールの検索枠を交互に割り当てる。
assertSame([0, 0], $mailSearchLimits->invoke($service, 0));
assertSame([0, 1], $mailSearchLimits->invoke($service, 1));
assertSame([1, 0], $mailSearchLimits->invoke($service, 1));
assertSame([1, 1], $mailSearchLimits->invoke($service, 2));

echo "LunchOrderService receipt test passed\n";

function assertSame(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException('Assertion failed: expected=' . var_export($expected, true) . ', actual=' . var_export($actual, true));
    }
}
