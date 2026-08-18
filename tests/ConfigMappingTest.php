<?php

declare(strict_types=1);

putenv('MAIL_NOTION_PROPERTY_MAPPINGS_JSON=[{"key":"curry_type","mail_labels":["カレーの種類"],"notion_property":"カレーの種類","notion_type":"select"}]');
putenv('GMAIL_PROCESSED_LABEL_NAME=order-lunch-status-processed');
putenv('MAIL_FIELD_NOTE_APPEND_LABELS=カレーの種類|ソースの種類');
putenv('MAIL_RECEIPT_FROM=receipts@example.com');
putenv('MAIL_KIMURA_ORDER_FROM=kimura@example.com');
putenv('MAIL_KIMURA_ORDER_SUBJECT=Kimura order');
putenv('GMAIL_MAX_MESSAGES_PER_RUN=75');
putenv('CURL_CA_BUNDLE=');

$config = require __DIR__ . '/../config.php';
$mapping = $config['mail_notion_property_mappings'][0] ?? null;

assertSame('curry_type', $mapping['key'] ?? null);
assertSame(['カレーの種類'], $mapping['mail_labels'] ?? null);
assertSame('カレーの種類', $mapping['notion_property'] ?? null);
assertSame('select', $mapping['notion_type'] ?? null);
assertSame('curry_type', $config['mail_parser']['mapped_fields'][0]['key'] ?? null);
assertSame('order-lunch-status-processed', $config['gmail_processed_label_name'] ?? null);
assertSame(['カレーの種類', 'ソースの種類'], $config['mail_parser']['note_append_labels'] ?? null);
assertSame('receipts@example.com', $config['mail_receipt_from'] ?? null);
assertSame('kimura@example.com', $config['mail_kimura_order_from'] ?? null);
assertSame('Kimura order', $config['mail_kimura_order_subject'] ?? null);
assertSame(75, $config['gmail_max_messages_per_run'] ?? null);

echo "Config mapping test passed\n";

function assertSame(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException('Assertion failed: expected=' . var_export($expected, true) . ', actual=' . var_export($actual, true));
    }
}
