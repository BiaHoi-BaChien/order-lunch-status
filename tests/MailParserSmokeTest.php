<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/MailParser.php';

date_default_timezone_set('Asia/Ho_Chi_Minh');

$parser = new MailParser();

$orderBody = implode("\n", [
    'お子様がお弁当を召し上がる日付を記載してください。',
    '5月8日（金）',
    'お手持ちのお弁当券に記載してある数字4ケタのお弁当ナンバー',
    'B13495',
    '注文したお弁当',
    'キムチ牛めし（B券：定食・丼）',
    'ライスの量',
    'M 200ｇ',
    '備考',
    '少なめ',
]);

$order = $parser->parseOrderConfirmation([
    'internalDate' => (string) (strtotime('2026-05-04 10:00:00') * 1000),
    'payload' => [
        'mimeType' => 'text/plain',
        'body' => ['data' => base64Url($orderBody)],
    ],
]);

assertSame('2026-05-08', $order['date']);
assertSame('B13495', $order['ticket_no']);
assertSame('キムチ牛めし（B券：定食・丼）', $order['item_name']);
assertSame('M', $order['size']);
assertSame('少なめ', $order['note']);

$numericTicketBody = str_replace('B13495', '1234', $orderBody);
$numericTicketOrder = $parser->parseOrderConfirmation([
    'internalDate' => (string) (strtotime('2026-05-04 10:00:00') * 1000),
    'payload' => [
        'mimeType' => 'text/plain',
        'body' => ['data' => base64Url($numericTicketBody)],
    ],
]);

assertSame('1234', $numericTicketOrder['ticket_no']);

$ticketWithFormNoiseBody = implode("\n", [
    'お子様がお弁当を召し上がる日付を記載してください。',
    '5月8日（金）',
    'お手持ちのお弁当券に記載してある数字4ケタのお弁当ナンバー',
    'い。 *',
    'B13495',
    '注文したお弁当',
    'キムチ牛めし（B券：定食・丼）',
    'ライスの量',
    'M 200ｇ',
    '備考',
    '少なめ',
]);
$ticketWithFormNoiseOrder = $parser->parseOrderConfirmation([
    'internalDate' => (string) (strtotime('2026-05-04 10:00:00') * 1000),
    'payload' => [
        'mimeType' => 'text/plain',
        'body' => ['data' => base64Url($ticketWithFormNoiseBody)],
    ],
]);

assertSame('B13495', $ticketWithFormNoiseOrder['ticket_no']);

$ticketWithImageAltNoiseBody = str_replace("い。 *", "説明のない画像", $ticketWithFormNoiseBody);
$ticketWithImageAltNoiseOrder = $parser->parseOrderConfirmation([
    'internalDate' => (string) (strtotime('2026-05-04 10:00:00') * 1000),
    'payload' => [
        'mimeType' => 'text/plain',
        'body' => ['data' => base64Url($ticketWithImageAltNoiseBody)],
    ],
]);

assertSame('B13495', $ticketWithImageAltNoiseOrder['ticket_no']);

$ticketHtmlBody = <<<HTML
<div><h2>お手持ちのお弁当券に記載してある数字4ケタのお弁当ナンバーを記載してください。<span aria-label="必須の質問"> *</span></h2><img alt="説明のない画像"></div><div><div><div style="white-space: pre-wrap;border-bottom: 1px dotted rgba(0,0,0,0.38);">B13495</div></div></div>
<div><h2>お子様がお弁当を召し上がる日付を記載してください。</h2></div><div><div style="border-bottom: 1px dotted rgba(0,0,0,0.38);">5月8日（金）</div></div>
<div><h2>注文したお弁当</h2></div><div><div style="border-bottom: 1px dotted rgba(0,0,0,0.38);">キムチ牛めし（B券：定食・丼）</div></div>
<div><h2>ライスの量</h2></div><div><div style="border-bottom: 1px dotted rgba(0,0,0,0.38);">M 200ｇ</div></div>
<div><h2>備考</h2></div><div><div style="border-bottom: 1px dotted rgba(0,0,0,0.38);">少なめ</div></div>
HTML;
$ticketHtmlOrder = $parser->parseOrderConfirmation([
    'internalDate' => (string) (strtotime('2026-05-04 10:00:00') * 1000),
    'payload' => [
        'mimeType' => 'text/html',
        'body' => ['data' => base64Url($ticketHtmlBody)],
    ],
]);

assertSame('B13495', $ticketHtmlOrder['ticket_no']);
assertSame('キムチ牛めし（B券：定食・丼）', $ticketHtmlOrder['item_name']);
assertSame('M', $ticketHtmlOrder['size']);
assertSame('少なめ', $ticketHtmlOrder['note']);

$sizeWithDescriptionHtmlBody = str_replace('M 200ｇ', 'S ライス150ｇ 牛めしの具65ｇ相当', $ticketHtmlBody);
$sizeWithDescriptionOrder = $parser->parseOrderConfirmation([
    'internalDate' => (string) (strtotime('2026-05-04 10:00:00') * 1000),
    'payload' => [
        'mimeType' => 'text/html',
        'body' => ['data' => base64Url($sizeWithDescriptionHtmlBody)],
    ],
]);

assertSame('S', $sizeWithDescriptionOrder['size']);

$emptyNoteHtmlBody = str_replace('少なめ', '', $ticketHtmlBody);
$emptyNoteOrder = $parser->parseOrderConfirmation([
    'internalDate' => (string) (strtotime('2026-05-04 10:00:00') * 1000),
    'payload' => [
        'mimeType' => 'text/html',
        'body' => ['data' => base64Url($emptyNoteHtmlBody)],
    ],
]);

assertSame('', $emptyNoteOrder['note']);

$checkboxNoteHtmlBody = str_replace(
    '<div><h2>備考</h2></div><div><div style="border-bottom: 1px dotted rgba(0,0,0,0.38);">少なめ</div></div>',
    '<div><h2>備考</h2></div><div><div><div role="checkbox" aria-checked="true" aria-label="つゆだく"></div><div role="checkbox" aria-checked="true" aria-label="ネギ抜き"></div></div></div>',
    $ticketHtmlBody
);
$checkboxNoteOrder = $parser->parseOrderConfirmation([
    'internalDate' => (string) (strtotime('2026-05-04 10:00:00') * 1000),
    'payload' => [
        'mimeType' => 'text/html',
        'body' => ['data' => base64Url($checkboxNoteHtmlBody)],
    ],
]);

assertSame('つゆだく、ネギ抜き', $checkboxNoteOrder['note']);

$curryHtmlBody = <<<HTML
<div><h2>お手持ちのお弁当券に記載してある数字4ケタのお弁当ナンバーを記載してください。</h2></div><div><div style="border-bottom: 1px dotted rgba(0,0,0,0.38);">B13495</div></div>
<div><h2>お子様がお弁当を召し上がる日付を記載してください。</h2></div><div><div style="border-bottom: 1px dotted rgba(0,0,0,0.38);">5月8日（金）</div></div>
<div><h2>注文したお弁当</h2></div><div><div role="radio" aria-checked="true" aria-label="チキンかつカレー（B券：定食・丼）"></div></div>
<div><h2>定食・丼のライスの量</h2></div>
<div>
  <div><h2>カレーの種類を選択して下さい。</h2></div>
  <div><div role="radio" aria-checked="true" aria-label="甘口（カレー粉由来の辛さがあるため、多少の辛さはあります）"></div></div>
  <div><h2>ライスの量を選択してください。</h2></div>
  <div><div role="radio" aria-checked="true" aria-label="S 150ｇ"></div></div>
</div>
<div><h2>備考</h2></div><div><div style="border-bottom: 1px dotted rgba(0,0,0,0.38);">ネギ抜き</div></div>
HTML;
$curryOrder = $parser->parseOrderConfirmation([
    'internalDate' => (string) (strtotime('2026-05-04 10:00:00') * 1000),
    'payload' => [
        'mimeType' => 'text/html',
        'body' => ['data' => base64Url($curryHtmlBody)],
    ],
]);

assertSame('チキンかつカレー（B券：定食・丼）', $curryOrder['item_name']);
assertSame('S', $curryOrder['size']);
assertSame('ネギ抜き、カレーの種類: 甘口（カレー粉由来の辛さがあるため、多少の辛さはあります）', $curryOrder['note']);

$receipt = $parser->parseReceipt([
    'internalDate' => (string) (strtotime('2026-05-04 10:00:00') * 1000),
    'payload' => [
        'mimeType' => 'text/plain',
        'body' => ['data' => base64Url('5月8日（金）のホーチミン日本人学校お弁当（松屋）の注文を受け付けました。')],
    ],
]);

assertSame('2026-05-08', $receipt['date']);

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
