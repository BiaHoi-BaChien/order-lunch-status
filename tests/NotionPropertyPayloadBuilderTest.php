<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/NotionPropertyPayloadBuilder.php';

$builder = new NotionPropertyPayloadBuilder();

$payload = $builder->build(
    [
        [
            'key' => 'curry_type',
            'notion_property' => 'カレーの種類',
            'notion_type' => 'select',
        ],
        [
            'key' => 'amount',
            'notion_property' => '金額',
            'notion_type' => 'number',
        ],
        [
            'key' => 'empty',
            'notion_property' => '空欄',
            'notion_type' => 'rich_text',
        ],
    ],
    [
        'curry_type' => '甘口',
        'amount' => '1,200',
        'empty' => '',
    ]
);

assertSame(['select' => ['name' => '甘口']], $payload['カレーの種類']);
assertSame(['number' => 1200], $payload['金額']);
assertSame(false, array_key_exists('空欄', $payload));

echo "NotionPropertyPayloadBuilder test passed\n";

function assertSame(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException('Assertion failed: expected=' . var_export($expected, true) . ', actual=' . var_export($actual, true));
    }
}
