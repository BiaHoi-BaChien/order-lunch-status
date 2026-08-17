<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/NotionClient.php';

$notion = new NotionClient('unused', 'unused', 'unused');
$method = new ReflectionMethod(NotionClient::class, 'blocksContainCaption');
$caption = 'RAMEN KIMURA QRコード (Gmail: message-id)';
$blocks = [[
    'type' => 'image',
    'image' => [
        'caption' => [['plain_text' => $caption]],
    ],
]];

if ($method->invoke($notion, $blocks, $caption) !== true) {
    throw new RuntimeException('QR画像の重複判定に失敗しました');
}
if ($method->invoke($notion, $blocks, 'another message') !== false) {
    throw new RuntimeException('別メールのQR画像を重複と誤判定しました');
}

echo "Notion image caption test passed\n";
