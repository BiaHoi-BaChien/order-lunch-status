<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/LunchOrderService.php';

$service = (new ReflectionClass(LunchOrderService::class))->newInstanceWithoutConstructor();
$isConfirmedKimuraOrder = new ReflectionMethod(LunchOrderService::class, 'isConfirmedKimuraOrder');

assertSame(true, $isConfirmedKimuraOrder->invoke($service, orderPage('RAMEN KIMURA', '注文済', 'https://mail.google.com/order')));
assertSame(true, $isConfirmedKimuraOrder->invoke($service, orderPage('RAMEN KIMURA', '受付済', 'https://mail.google.com/order')));
assertSame(false, $isConfirmedKimuraOrder->invoke($service, orderPage(null, '未注文', null)));
assertSame(false, $isConfirmedKimuraOrder->invoke($service, orderPage('松屋', '注文済', 'https://mail.google.com/order')));
assertSame(false, $isConfirmedKimuraOrder->invoke($service, orderPage('RAMEN KIMURA', '注文済', null)));

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
