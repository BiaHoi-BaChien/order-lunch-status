<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/MailParser.php';

date_default_timezone_set('Asia/Ho_Chi_Minh');
$parser = new MailParser();
$receivedAt = '2026-11-14 09:05:48';
$body = <<<TEXT
テスト利用者 様

ご注文ありがとうございます。
注文番号：M261114-0005
お渡し希望日：11月18日（水）

・おむすび 塩 × 1個（単価：22,000 VND）
・おかずセット × 1個（単価：50,000 VND）

合計金額：72,000 VND

入金確認後に確認メールをお送りします。
TEXT;
$body = str_replace("\r\n", "\n", $body);
$html = <<<HTML
<!DOCTYPE html><html><body>
<h1>ご注文・お支払いのご案内</h1>
<p>テスト利用者 様</p><p>ご注文ありがとうございます。</p>
<p>注文番号：M261114-0005<br>お渡し希望日：11月18日（水）</p>
<p>ご注文内容</p>
<table role="presentation">
<tr><th>商品名</th><th>数量</th><th>単価</th></tr>
<tr><td>おむすび 塩</td><td>1個</td><td>22,000 VND</td></tr>
<tr><td>おかずセット</td><td>1個</td><td>50,000 VND</td></tr>
</table>
<p>合計金額：72,000 VND</p>
<p><img src="cid:bankqr" alt="銀行振込用QRコード"></p>
<p>入金確認後に確認メールをお送りします。</p>
</body></html>
HTML;
$expected = [
    'date' => '2026-11-18',
    'item_name' => 'おむすび 塩、おかずセット',
    'note' => '注文番号: M261114-0005、合計金額: 72,000 VND',
];
$plainPart = mailPart('text/plain', $body);
$htmlPart = mailPart('text/html', $html);
foreach ([
    $plainPart,
    $htmlPart,
    ['mimeType' => 'multipart/related', 'parts' => [
        ['mimeType' => 'multipart/alternative', 'parts' => [$plainPart, $htmlPart]],
        ['mimeType' => 'image/png', 'body' => ['data' => base64_encode('test-image')]],
    ]],
    mailPart('text/plain', str_replace('11月18日', '１１月１８日', $body)),
    mailPart('text/plain', str_replace("\n", "\r\n", $body)),
    mailPart('text/html', str_replace(' VND', '&nbsp;VND', $html)),
] as $payload) {
    assertSame($expected, parseMail($payload));
}

// 年のない希望日は受信日から補完し、年をまたぐ場合も既存の日付処理に合わせる。
$receivedAt = '2026-12-31 09:00:00';
assertSame('2027-01-04', parseMail(mailPart('text/plain', str_replace('11月18日（水）', '1月4日（月）', $body)))['date']);
$receivedAt = '2027-01-01 09:00:00';
assertSame('2026-12-31', parseMail(mailPart('text/plain', str_replace('11月18日（水）', '12月31日（木）', $body)))['date']);
$receivedAt = '2026-11-14 09:05:48';

$quantityOrder = parseMail(mailPart('text/plain', str_replace('おむすび 塩 × 1個', 'おむすび 塩 × 2個', $body)));
assertSame($expected['item_name'], $quantityOrder['item_name']);
assertSame($expected['note'] . '、数量: おむすび 塩 × 2', $quantityOrder['note']);

// 桁区切りや金額の空白だけが異なる本文は、同じ注文として扱う。
assertSame($expected, parseMail(['mimeType' => 'multipart/alternative', 'parts' => [
    $plainPart,
    mailPart('text/html', str_replace(['22,000 VND', '50,000 VND', '72,000 VND'], ['22000VND', '50000 VND', '72000VND'], $html)),
]]));

foreach ([
    str_replace('M261114-0005', '', $body),
    str_replace('お渡し希望日：11月18日（水）', '', $body),
    str_replace('11月18日（水）', '2月30日（月）', $body),
    str_replace('11月18日（水）', '日付なし', $body),
    str_replace('× 1個', '× 0個', $body),
    str_replace('× 1個', '× -1個', $body),
    str_replace('おむすび 塩 × 1個', '× 1個', $body),
    str_replace('おむすび 塩 × 1個', '   × 1個', $body),
    str_replace('22,000 VND', '金額なし', $body),
    str_replace('合計金額：72,000 VND', '', $body),
    str_replace('合計金額：72,000 VND', '合計金額：abc VND', $body),
    str_replace('合計金額：72,000 VND', '合計金額：72,00 VND', $body),
    str_replace('合計金額：72,000 VND', '合計金額：72,000 VND 不明', $body),
    "注文番号：M261114-0005\nお渡し希望日：11月18日（水）\n合計金額：72,000 VND",
    '入金確認後に確認メールをお送りします。',
] as $invalidBody) {
    assertRejected(mailPart('text/plain', $invalidBody));
}

// テキスト版とHTML版に相違や欠落があれば誤登録を防ぐ。
foreach ([
    str_replace('M261114-0005', 'M261114-0006', $html),
    str_replace('11月18日', '11月19日', $html),
    str_replace('おむすび 塩', 'おむすび 鮭', $html),
    str_replace('1個', '2個', $html),
    str_replace('22,000 VND', '24,000 VND', $html),
    str_replace('72,000 VND', '74,000 VND', $html),
    str_replace('合計金額：72,000 VND', '', $html),
] as $differentHtml) {
    assertRejected(['mimeType' => 'multipart/alternative', 'parts' => [$plainPart, mailPart('text/html', $differentHtml)]]);
}
assertRejected(mailPart('text/plain', $body . "\n" . str_replace('M261114-0005', 'M261114-0006', $body)));

echo "MailParser KORORI test passed\n";

function mailPart(string $type, string $body): array
{
    return ['mimeType' => $type, 'body' => ['data' => rtrim(strtr(base64_encode($body), '+/', '-_'), '=')]];
}

function parseMail(array $payload): array
{
    global $parser, $receivedAt;
    return $parser->parseKororiOrderConfirmation([
        'internalDate' => (string) (strtotime($receivedAt) * 1000),
        'payload' => $payload,
    ]);
}

function assertRejected(array $payload): void
{
    try {
        parseMail($payload);
    } catch (RuntimeException) {
        return;
    }
    throw new RuntimeException('不正または複数の注文を受理しました');
}

function assertSame(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException('Assertion failed: expected=' . var_export($expected, true) . ', actual=' . var_export($actual, true));
    }
}
