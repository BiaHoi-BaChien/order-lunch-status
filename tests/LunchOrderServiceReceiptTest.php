<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/LunchOrderService.php';

$service = (new ReflectionClass(LunchOrderService::class))->newInstanceWithoutConstructor();
$turnPath = sys_get_temp_dir() . '/order-lunch-status-receipt-turn-' . bin2hex(random_bytes(8)) . '.state';
register_shutdown_function(static fn () => is_file($turnPath) && unlink($turnPath));
(new ReflectionProperty(LunchOrderService::class, 'config'))->setValue($service, [
    'gmail_receipt_turn_path' => $turnPath,
]);
$isConfirmedKimuraOrder = new ReflectionMethod(LunchOrderService::class, 'isConfirmedKimuraOrder');
$receiptSearchLimits = new ReflectionMethod(LunchOrderService::class, 'receiptSearchLimits');

assertSame(true, $isConfirmedKimuraOrder->invoke($service, orderPage('RAMEN KIMURA', '注文済', 'https://mail.google.com/order')));
assertSame(true, $isConfirmedKimuraOrder->invoke($service, orderPage('RAMEN KIMURA', '受付済', 'https://mail.google.com/order')));
assertSame(false, $isConfirmedKimuraOrder->invoke($service, orderPage(null, '未注文', null)));
assertSame(false, $isConfirmedKimuraOrder->invoke($service, orderPage('松屋', '注文済', 'https://mail.google.com/order')));
assertSame(false, $isConfirmedKimuraOrder->invoke($service, orderPage('RAMEN KIMURA', '注文済', null)));
assertSame([0, 1], $receiptSearchLimits->invoke($service, 1));
assertSame([1, 0], $receiptSearchLimits->invoke($service, 1));
assertSame([1, 1], $receiptSearchLimits->invoke($service, 2));

echo "LunchOrderService receipt test passed\n";

function orderPage(?string $shop, ?string $status, ?string $orderUrl): array
{
    return ['properties' => [
        'お店' => ['select' => $shop === null ? null : ['name' => $shop]],
        '状況' => ['select' => $status === null ? null : ['name' => $status]],
        '注文確認メール' => ['url' => $orderUrl],
    ]];
}

function assertSame(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException('Assertion failed: expected=' . var_export($expected, true) . ', actual=' . var_export($actual, true));
    }
}
