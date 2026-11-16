<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/MailParser.php';

date_default_timezone_set('Asia/Ho_Chi_Minh');
$parser = new MailParser();
$receivedAt = '2026-11-16 09:04:43';
$body = "テスト利用者 様\n\nお支払いを確認いたしました。\n注文番号：M261114-0005\nお渡し希望日：11月18日（水）\nお支払い金額：72,000 VND\n\nご注文ありがとうございました。";
$html = '<html><body><h1>お支払い確認のお知らせ</h1><p>テスト利用者 様</p><p>お支払いを確認いたしました。</p><p>注文番号：M261114-0005<br>お渡し希望日：11月18日（水）<br>お支払い金額：<strong>72,000 VND</strong></p><p>ご注文ありがとうございました。</p></body></html>';
$expected = ['date' => '2026-11-18', 'order_no' => 'M261114-0005', 'amount' => '72000'];
$plainPart = mailPart('text/plain', $body);
$htmlPart = mailPart('text/html', $html);
foreach ([
    $plainPart,
    $htmlPart,
    ['mimeType' => 'multipart/alternative', 'parts' => [$plainPart, $htmlPart]],
    mailPart('text/plain', str_replace("\n", "\r\n", $body)),
    mailPart('text/plain', str_replace('11月18日', '１１月１８日', $body)),
    ['mimeType' => 'multipart/related', 'parts' => [
        $plainPart,
        mailPart('text/html', str_replace('72,000 VND', '72000&nbsp;VND', $html)),
    ]],
] as $payload) {
    assertSame($expected, parseMail($payload));
}

$receivedAt = '2026-12-31 09:00:00';
assertSame('2027-01-04', parseMail(mailPart('text/plain', str_replace('11月18日（水）', '1月4日（月）', $body)))['date']);
$receivedAt = '2027-01-01 09:00:00';
assertSame('2026-12-31', parseMail(mailPart('text/plain', str_replace('11月18日（水）', '12月31日（木）', $body)))['date']);
$receivedAt = '2026-11-16 09:04:43';

foreach ([
    str_replace('お支払いを確認いたしました。', '入金確認後に確認メールをお送りします。', $body),
    str_replace('M261114-0005', '', $body),
    str_replace('お渡し希望日：11月18日（水）', '', $body),
    str_replace('11月18日（水）', '2月30日（月）', $body),
    str_replace('お支払い金額：72,000 VND', '', $body),
    str_replace('72,000 VND', '72,00 VND', $body),
    str_replace('72,000 VND', '-72,000 VND', $body),
    str_replace('72,000 VND', '72,000 VND 不明', $body),
    $body . "\n注文番号：M261114-0006",
    $body . "\n" . str_replace('M261114-0005', 'M261114-0006', $body),
] as $invalidBody) {
    assertRejected(mailPart('text/plain', $invalidBody));
}

foreach ([
    str_replace('M261114-0005', 'M261114-0006', $html),
    str_replace('11月18日', '11月19日', $html),
    str_replace('72,000 VND', '74,000 VND', $html),
    str_replace('お支払い金額：<strong>72,000 VND</strong>', '', $html),
    str_replace('お支払いを確認いたしました。', '未入金です。', $html),
] as $differentHtml) {
    assertRejected(['mimeType' => 'multipart/alternative', 'parts' => [$plainPart, mailPart('text/html', $differentHtml)]]);
}

echo "MailParser KORORI receipt test passed\n";

function mailPart(string $type, string $body): array
{
    return ['mimeType' => $type, 'body' => ['data' => rtrim(strtr(base64_encode($body), '+/', '-_'), '=')]];
}

function parseMail(array $payload): array
{
    global $parser, $receivedAt;
    return $parser->parseKororiReceiptConfirmation([
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
    throw new RuntimeException('不正または複数の支払い確認を受理しました');
}

function assertSame(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException('Assertion failed: expected=' . var_export($expected, true) . ', actual=' . var_export($actual, true));
    }
}
