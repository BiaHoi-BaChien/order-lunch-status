<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/LunchOrderService.php';

$source = (string) file_get_contents(__DIR__ . '/../src/LunchOrderService.php');

$orderAuthentication = strpos($source, "assertAuthentic(\$message, (string) \$this->config['matsuya_mail_order_from'])");
$orderParsing = strpos($source, 'parseOrderConfirmation($message)');
$kimuraAuthentication = strpos($source, "assertAuthentic(\$message, (string) \$this->config['ramen_kimura_mail_order_from'])");
$kimuraParsing = strpos($source, 'parseKimuraOrderConfirmation($message)');
$receiptAuthentication = strpos($source, 'assertAuthentic($message, $expectedSender)');
$receiptParsing = strpos($source, 'parseReceipt($message)');
$kimuraReceiptParsing = strpos($source, 'parseKimuraReceipt($message)');
$kimuraReceiptTargetGuard = strpos($source, 'if ($isKimura && !$this->isConfirmedKimuraOrder($page))');
$receiptStatusUpdate = strpos($source, "'状況' => ['select' => ['name' => '受付済']]");

assertBefore($orderAuthentication, $orderParsing, '注文確認メール');
assertBefore($kimuraAuthentication, $kimuraParsing, 'RAMEN KIMURA注文確認メール');
assertBefore($receiptAuthentication, $receiptParsing, '受付確認メール');
assertBefore($receiptAuthentication, $kimuraReceiptParsing, 'RAMEN KIMURA受付確認メール');
assertBefore($kimuraReceiptTargetGuard, $receiptStatusUpdate, 'RAMEN KIMURA受付対象確認');

$service = (new ReflectionClass(LunchOrderService::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(LunchOrderService::class, 'config'))->setValue($service, [
    'lookback_days' => 7,
    'gmail_processed_label_name' => '',
]);
$query = (new ReflectionMethod(LunchOrderService::class, 'gmailSearchQuery'))->invoke(
    $service,
    '受付確認',
    'receipt-a@example.com｜receipt-b@example.com'
);
assertSame('{from:receipt-a@example.com from:receipt-b@example.com} subject:"受付確認" newer_than:7d', $query);

$query = (new ReflectionMethod(LunchOrderService::class, 'gmailSearchQuery'))->invoke(
    $service,
    'KIMURA受付確認',
    ' receipt-a@example.com | | receipt-b@example.com '
);
assertSame('{from:receipt-a@example.com from:receipt-b@example.com} subject:"KIMURA受付確認" newer_than:7d', $query);

echo "LunchOrderService security test passed\n";

function assertBefore(int|false $first, int|false $second, string $label): void
{
    if ($first === false || $second === false || $first >= $second) {
        throw new RuntimeException("Assertion failed: {$label}は解析前に認証されていません");
    }
}

function assertSame(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException('Assertion failed: expected=' . var_export($expected, true) . ', actual=' . var_export($actual, true));
    }
}
