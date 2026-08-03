<?php

declare(strict_types=1);

$source = (string) file_get_contents(__DIR__ . '/../src/LunchOrderService.php');

$orderAuthentication = strpos($source, "assertAuthentic(\$message, (string) \$this->config['mail_order_from'])");
$orderParsing = strpos($source, 'parseOrderConfirmation($message)');
$receiptAuthentication = strpos($source, "assertAuthentic(\$message, (string) \$this->config['mail_receipt_from'])");
$receiptParsing = strpos($source, 'parseReceipt($message)');

assertBefore($orderAuthentication, $orderParsing, '注文確認メール');
assertBefore($receiptAuthentication, $receiptParsing, '受付確認メール');

echo "LunchOrderService security test passed\n";

function assertBefore(int|false $first, int|false $second, string $label): void
{
    if ($first === false || $second === false || $first >= $second) {
        throw new RuntimeException("Assertion failed: {$label}は解析前に認証されていません");
    }
}
