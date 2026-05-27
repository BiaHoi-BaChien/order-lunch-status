<?php

declare(strict_types=1);

putenv('MAIL_NOTION_PROPERTY_MAPPINGS_JSON=[{"key":"curry_type","mail_labels":["カレーの種類"],"notion_property":"カレーの種類","notion_type":"select"}]');

$config = require __DIR__ . '/../config.php';
$mapping = $config['mail_notion_property_mappings'][0] ?? null;

assertSame('curry_type', $mapping['key'] ?? null);
assertSame(['カレーの種類'], $mapping['mail_labels'] ?? null);
assertSame('カレーの種類', $mapping['notion_property'] ?? null);
assertSame('select', $mapping['notion_type'] ?? null);
assertSame('curry_type', $config['mail_parser']['mapped_fields'][0]['key'] ?? null);

echo "Config mapping test passed\n";

function assertSame(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException('Assertion failed: expected=' . var_export($expected, true) . ', actual=' . var_export($actual, true));
    }
}
