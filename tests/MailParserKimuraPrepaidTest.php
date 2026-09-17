<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/MailParser.php';

$parser = new MailParser();
$body = <<<TEXT
ご注文ありがとうございます。下記の内容で承りました。

  2026-09-21 テスト利用者
    チャーハン唐揚げ弁当 × 1  80,000 VND

  お支払い  80,000 VND（事前チャージ残高からお引き落とし）
  お引き落とし後の残高  0 VND

内容の変更・お取り消しは、締切時刻まで注文画面のマイページから承ります。

------------------------------
Kính chào chị, chúng em đã nhận đơn đặt hàng ạ.
  Tổng cộng: 80,000 VND (trừ từ số dư đã nạp)
  Số dư còn lại: 0 VND
Chị có thể huỷ đơn trong trang cá nhân trước giờ chốt đơn ạ.

RAMEN KIMURA
TEXT;
$expected = [
    'date' => '2026-09-21',
    'item_name' => 'チャーハン唐揚げ弁当',
    'note' => '合計金額: 80,000 VND',
];
$html = '<div>' . str_replace("\n", '</div><div>', htmlspecialchars($body, ENT_QUOTES, 'UTF-8')) . '</div>';
$plainPart = mailPart('text/plain', $body);
$htmlPart = mailPart('text/html', $html);

foreach ([
    $plainPart,
    $htmlPart,
    ['mimeType' => 'multipart/alternative', 'parts' => [$plainPart, $htmlPart]],
    ['mimeType' => 'multipart/alternative', 'parts' => [mailPart('text/plain', 'ご注文ありがとうございます。'), $htmlPart]],
    mailPart('text/plain', str_replace('2026-09-21', '２０２６-０９-２１', $body)),
    mailPart('text/html', str_replace('80,000 VND', '80,000&nbsp;VND', $html)),
] as $payload) {
    assertSame($expected, $parser->parseKimuraOrderConfirmation(['payload' => $payload]));
}

// 金額の空白・桁区切りだけが違う本文は同じ注文として扱う。
foreach (['80,000VND', '80000 VND', '80000VND', '080000 VND'] as $amount) {
    assertSame($expected, $parser->parseKimuraOrderConfirmation([
        'payload' => [
            'mimeType' => 'multipart/alternative',
            'parts' => [$plainPart, mailPart('text/html', str_replace('80,000 VND', $amount, $html))],
        ],
    ]));
    assertSame(array_replace($expected, ['note' => '合計金額: ' . $amount]), $parser->parseKimuraOrderConfirmation([
        'payload' => [
            'mimeType' => 'multipart/alternative',
            'parts' => [mailPart('text/plain', str_replace('80,000 VND', $amount, $body)), $htmlPart],
        ],
    ]));
}
assertSame($expected, $parser->parseKimuraOrderConfirmation([
    'payload' => [
        'mimeType' => 'multipart/alternative',
        'parts' => [$plainPart, mailPart('text/html', str_replace('80,000 VND', '80,000&nbsp; VND', $html))],
    ],
]));

$quantityOrder = $parser->parseKimuraOrderConfirmation([
    'payload' => mailPart('text/plain', str_replace(['× 1', '80,000'], ['× 2', '160,000'], $body)),
]);
assertSame('チャーハン唐揚げ弁当', $quantityOrder['item_name']);
assertSame('合計金額: 160,000 VND、数量: 2', $quantityOrder['note']);

foreach ([
    str_replace('2026-09-21', '2026-02-30', $body),
    str_replace('2026-09-21', '', $body),
    str_replace('チャーハン唐揚げ弁当 × 1  80,000 VND', '', $body),
    str_replace('お支払い  80,000 VND', 'お支払い', $body),
    str_replace('× 1', '× 0', $body),
    str_replace('チャーハン唐揚げ弁当 × 1  80,000 VND', "チャーハン唐揚げ弁当 × 1  80,000 VND\n    カレー × 1  80,000 VND", $body),
    str_replace('  お支払い', "  2026-09-22 別の利用者\n    カレー × 1  80,000 VND\n\n  お支払い", $body),
    $body . "\n\n" . str_replace('2026-09-21', '2026-09-22', $body),
] as $invalidBody) {
    assertRejected($parser, mailPart('text/plain', $invalidBody));
}

// MIMEの各表現で注文内容が異なる場合、先頭の内容だけを登録しない。
foreach ([
    str_replace('80,000', '90,000', $html),
    str_replace('お支払い  80,000 VND', 'お支払い  90000VND', $html),
    str_replace('× 1', '× 2', $html),
] as $conflictingHtml) {
    assertRejected($parser, [
        'mimeType' => 'multipart/alternative',
        'parts' => [$plainPart, mailPart('text/html', $conflictingHtml)],
    ]);
}

echo "MailParser KIMURA prepaid test passed\n";

function mailPart(string $mimeType, string $body): array
{
    return [
        'mimeType' => $mimeType,
        'body' => ['data' => rtrim(strtr(base64_encode($body), '+/', '-_'), '=')],
    ];
}

function assertRejected(MailParser $parser, array $payload): void
{
    try {
        $parser->parseKimuraOrderConfirmation(['payload' => $payload]);
    } catch (RuntimeException $e) {
        assertSame(true, str_contains($e->getMessage(), 'RAMEN KIMURA'));
        return;
    }

    throw new RuntimeException('Invalid KIMURA order was accepted');
}

function assertSame(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException('Assertion failed: expected=' . var_export($expected, true) . ', actual=' . var_export($actual, true));
    }
}
