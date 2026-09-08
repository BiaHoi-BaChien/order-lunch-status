<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/MailParser.php';

date_default_timezone_set('Asia/Ho_Chi_Minh');

$parser = new MailParser();
$mappedParser = new MailParser([
    'mapped_fields' => [
        [
            'key' => 'customization',
            'mail_labels' => ['カスタマイズ'],
        ],
    ],
]);

$newMatsuyaBody = <<<TEXT
※このメールはシステムからの自動送信です。

お弁当のご注文を以下の通り受け付けました。
内容にお間違いがないかご確認ください。

【1人目】
・学年 / クラス: 小5 / 3組
・氏名（日本語）: 杉山福
・氏名（ローマ字）: SUGIYAMA FUKU

[2026-09-09]
・お弁当券ナンバー: B10788
・メニュー: キムチ牛めし　B券
・サイズ: S
・カスタマイズ: ネギ抜き,つゆ多め
・その他の要望: なし
TEXT;
$newMatsuyaOrder = $parser->parseOrderConfirmation([
    'internalDate' => (string) (strtotime('2026-09-07 19:04:00') * 1000),
    'payload' => [
        'mimeType' => 'text/plain',
        'body' => ['data' => base64Url($newMatsuyaBody)],
    ],
]);

assertSame('2026-09-09', $newMatsuyaOrder['date']);
assertSame('B10788', $newMatsuyaOrder['ticket_no']);
assertSame('キムチ牛めし B券', $newMatsuyaOrder['item_name']);
assertSame('S', $newMatsuyaOrder['size']);
assertSame('なし、カスタマイズ: ネギ抜き,つゆ多め', $newMatsuyaOrder['note']);

$mappedMatsuyaOrder = $mappedParser->parseOrderConfirmation([
    'payload' => [
        'mimeType' => 'text/plain',
        'body' => ['data' => base64Url($newMatsuyaBody)],
    ],
]);
assertSame('ネギ抜き,つゆ多め', $mappedMatsuyaOrder['mapped_fields']['customization'] ?? null);

try {
    $parser->parseOrderConfirmation([
        'payload' => [
            'mimeType' => 'text/plain',
            'body' => ['data' => base64Url("お弁当を召し上がる日付\n9月9日（水）\nお弁当番号\nB10788")],
        ],
    ]);
    throw new RuntimeException('旧形式の松屋注文確認メールを受理しました');
} catch (RuntimeException $e) {
    assertSame('注文日付を抽出できません', $e->getMessage());
}

$kimuraBody = <<<TEXT
ご注文ありがとうございます。

氏名：杉山福
学年：小学5年
クラス：3組
出席番号：8
注文日：2026年08月19日
メニュー：2026年08月19日メニュー：チャーハン唐揚げ弁当 80,000VND
合計金額：80000VND

以下のQRコードを銀行アプリで読み取り、本日23:59までに送金してください。
TEXT;
$kimuraOrder = $parser->parseKimuraOrderConfirmation([
    'payload' => [
        'mimeType' => 'text/plain',
        'body' => ['data' => base64Url($kimuraBody)],
    ],
]);

assertSame('2026-08-19', $kimuraOrder['date']);
assertSame('チャーハン唐揚げ弁当', $kimuraOrder['item_name']);
assertSame('合計金額: 80000VND', $kimuraOrder['note']);

$receipt = $parser->parseReceipt([
    'internalDate' => (string) (strtotime('2026-05-04 10:00:00') * 1000),
    'payload' => [
        'mimeType' => 'text/plain',
        'body' => ['data' => base64Url('5月8日（金）のホーチミン日本人学校お弁当（松屋）の注文を受け付けました。')],
    ],
]);

assertSame('2026-05-08', $receipt['date']);

$kimuraReceiptBody = <<<TEXT
保護者さま

ご入金を確認し、下記のご注文が確定しました。

・お子様の氏名：****
・お届け日：2026/08/25
・メニュー：チャーハン唐揚げ弁当 80,000VND
・お支払い額：80,000 VND

当日、学校へお届けいたします。
よろしくお願いいたします。

RAMEN KIMURA
TEXT;
$kimuraReceipt = $parser->parseKimuraReceipt([
    'payload' => [
        'mimeType' => 'text/plain',
        'body' => ['data' => base64Url($kimuraReceiptBody)],
    ],
]);
assertSame('2026-08-25', $kimuraReceipt['date']);

$kimuraReceiptWithSpaces = $parser->parseKimuraReceipt([
    'payload' => [
        'mimeType' => 'text/plain',
        'body' => ['data' => base64Url('お届け日 ： ２０２６ / ８ / ２５')],
    ],
]);
assertSame('2026-08-25', $kimuraReceiptWithSpaces['date']);

foreach (['お届け日：2026/02/30', 'ご注文が確定しました。'] as $invalidKimuraReceiptBody) {
    try {
        $parser->parseKimuraReceipt([
            'payload' => [
                'mimeType' => 'text/plain',
                'body' => ['data' => base64Url($invalidKimuraReceiptBody)],
            ],
        ]);
        throw new RuntimeException('Invalid RAMEN KIMURA receipt date was not rejected');
    } catch (RuntimeException $e) {
        assertSame(true, str_contains($e->getMessage(), 'RAMEN KIMURA受付メール'));
    }
}

$receiptWithNoise = $parser->parseReceipt([
    'internalDate' => (string) (strtotime('2026-05-04 10:00:00') * 1000),
    'payload' => [
        'mimeType' => 'text/plain',
        'body' => ['data' => base64Url("受付番号 5月0日\n5月8日（金）のホーチミン日本人学校お弁当（松屋）の注文を受け付けました。")],
    ],
]);

assertSame('2026-05-08', $receiptWithNoise['date']);

$receiptWithFullWidthDate = $parser->parseReceipt([
    'internalDate' => (string) (strtotime('2026-05-04 10:00:00') * 1000),
    'payload' => [
        'mimeType' => 'text/plain',
        'body' => ['data' => base64Url('5月６日（水）のホーチミン日本人学校お弁当（松屋）の注文を受け付けました。')],
    ],
]);

assertSame('2026-05-06', $receiptWithFullWidthDate['date']);

try {
    $parser->parseReceipt([
        'internalDate' => (string) (strtotime('2026-05-04 10:00:00') * 1000),
        'payload' => [
            'mimeType' => 'text/plain',
            'body' => ['data' => base64Url('5月0日（月）のホーチミン日本人学校お弁当（松屋）の注文を受け付けました。')],
        ],
    ]);
    throw new RuntimeException('Invalid receipt date was not rejected');
} catch (RuntimeException $e) {
    assertSame(true, str_contains($e->getMessage(), '実在しない日付です'));
}

echo "MailParser smoke test passed\n";

function base64Url(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function assertSame(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException('Assertion failed: expected=' . var_export($expected, true) . ', actual=' . var_export($actual, true));
    }
}
