<?php

declare(strict_types=1);

// APIクライアントだけを置き換え、実際のサービス・解析・送信元認証を通す。
require_once __DIR__ . '/../src/MailParser.php';
require_once __DIR__ . '/../src/GmailMessageAuthenticator.php';
require_once __DIR__ . '/../src/LunchOrderService.php';

date_default_timezone_set('Asia/Ho_Chi_Minh');

final class GmailClient
{
    public array $queries = [];
    public array $labels = [];
    public int $qrReads = 0;

    public function __construct(public array $messages) {}

    public function searchMessages(string $query, int $limit): array
    {
        if ($limit < 1) {
            throw new RuntimeException('Invalid search limit');
        }
        $this->queries[] = [$query, $limit];
        preg_match('/subject:"([^"]+)"/', $query, $subject);
        $matches = [];
        foreach ($this->messages as $id => $message) {
            if (!isset($this->labels[$id]) && str_contains($message['subject'], $subject[1])) {
                $matches[] = ['id' => $id, 'threadId' => $id];
            }
        }
        return array_slice($matches, 0, $limit);
    }

    public function getMessage(string $id): array { return $this->messages[$id]; }
    public function messageUrl(string $id): string { return 'https://mail.google.com/mail/u/0/#all/' . $id; }
    public function addLabel(string $id, string $label): void { $this->labels[$id] = $label; }
    public function extractQrImage(string $id, array $message): ?array
    {
        $this->qrReads++;
        return $message['qr'] ?? null;
    }
}

final class NotionClient
{
    public array $updates = [];
    public array $images = [];
    public bool $failNextUpdate = false;

    public function __construct(public array $pages) {}
    public function findOrderByDate(string $date): ?array { return $this->pages[$date] ?? null; }
    public function findOrdersByDateRange(string $start, string $end): array { return array_values($this->pages); }
    public function createInitialOrder(string $date, string $weekday, string $status): array
    {
        return $this->pages[$date] = orderPage($date, null, $status);
    }
    public function findTicketByNumber(string $number): ?array { return ['id' => 'ticket-' . $number]; }
    public function updateOrder(string $id, array $properties): array
    {
        if ($this->failNextUpdate) {
            $this->failNextUpdate = false;
            throw new RuntimeException('Simulated Notion failure');
        }
        $this->updates[] = [$id, $properties];
        $this->pages[$id]['properties'] = array_replace($this->pages[$id]['properties'], $properties);
        return $this->pages[$id];
    }
    public function appendExternalImageIfMissing(string $id, string $url, string $caption): void
    {
        $this->images[$id][$caption] ??= $url;
    }
    public function appendImageIfMissing(string $id, string $data, string $mime, string $caption): void
    {
        $this->images[$id][$caption] ??= $data;
    }
}

final class Logger
{
    public function info(string $message): void {}
    public function warn(string $message): void {}
    public function error(string $message): void {}
}

$kimuraBody = "ご注文ありがとうございます。下記の内容で承りました。\n2026-09-21 テスト利用者\nチャーハン唐揚げ弁当 × 1 80,000 VND\nお支払い 80,000 VND（事前チャージ残高からお引き落とし）";
$kimuraMail = testMessage('ご注文を承りました  ', $kimuraBody, 'kimura@example.com');
$kimuraUrl = 'https://mail.google.com/mail/u/0/#all/kimura';

// 1通だけで未注文から受付済へ。旧メールは検索しない。
[$service, $gmail, $notion] = fixture([
    'kimura' => $kimuraMail,
    'old-order' => testMessage('【お弁当注文確認】', '旧注文', 'kimura@example.com'),
    'old-receipt' => testMessage('【弁当注文】ご注文が確定しました（ご入金を確認しました）', 'お届け日：2026/09/21', 'kimura@example.com'),
]);
$result = $service->run();
assertSame(0, $result['errors']);
assertSame(0, $result['order_confirmation_found']);
assertSame(1, $result['receipt_success']);
$properties = $notion->pages['2026-09-21']['properties'];
assertSame('受付済', $properties['状況']['select']['name']);
assertSame('RAMEN KIMURA', $properties['お店']['select']['name']);
assertSame('チャーハン唐揚げ弁当', $properties['品名']['title'][0]['text']['content']);
assertSame('合計金額: 80,000 VND', $properties['備考']['rich_text'][0]['text']['content']);
assertSame($kimuraUrl, $properties['注文確認メール']['url']);
assertSame($kimuraUrl, $properties['受付確認メール']['url']);
assertSame(['kimura' => 'processed'], $gmail->labels);
assertSame(3, count($gmail->queries));
assertSame('from:kimura@example.com subject:"ご注文を承りました" newer_than:7d -label:"processed"', $gmail->queries[2][0]);
assertSame(0, count($notion->images));

// QRなしでも、ラベルを外して再実行した同一メールは重複更新しない。
$gmail->labels = [];
$result = $service->run();
assertSame(1, $result['receipt_skipped']);
assertSame(1, count($notion->updates));
assertSame(1, $gmail->qrReads);

// 旧処理の注文済レコードも、同じメールならQRの有無にかかわらず受付済に進む。
foreach ([null, ['url' => 'https://example.com/qr.png'], ['data' => 'image', 'mime_type' => 'image/png']] as $qr) {
    [$service, $gmail, $notion] = fixture(['kimura' => $kimuraMail + ['qr' => $qr]], [
        '2026-09-21' => orderPage('2026-09-21', 'RAMEN KIMURA', '注文済', $kimuraUrl),
    ]);
    if ($qr !== null) {
        $notion->images['2026-09-21']['RAMEN KIMURA QRコード (Gmail: kimura)'] = 'existing-image';
    }
    assertSame(1, $service->run()['receipt_success']);
    assertSame('受付済', $notion->pages['2026-09-21']['properties']['状況']['select']['name']);
    assertSame($qr === null ? 0 : 1, count($notion->images['2026-09-21'] ?? []));
}

// 画像追加後の更新失敗はラベル付与せず、次回に重複画像なしで完了する。
[$service, $gmail, $notion] = fixture(['kimura' => $kimuraMail + ['qr' => ['url' => 'https://example.com/qr.png']]]);
$notion->failNextUpdate = true;
assertSame(1, $service->run()['errors']);
assertSame([], $gmail->labels);
assertSame(1, $service->run()['receipt_success']);
assertSame(1, count($notion->images['2026-09-21']));

// 同日の別注文・別店舗を上書きしない。
foreach ([['RAMEN KIMURA', '注文済', 'different'], ['松屋', '受付済', $kimuraUrl]] as [$shop, $status, $url]) {
    [$service, $gmail, $notion] = fixture(['kimura' => $kimuraMail], [
        '2026-09-21' => orderPage('2026-09-21', $shop, $status, $url),
    ]);
    assertSame(1, $service->run()['errors']);
    assertSame([], $notion->updates);
    assertSame([], $gmail->labels);
    assertSame(0, $gmail->qrReads);
}

// 送信元不一致、認証失敗、不正本文、日付レコード欠落は更新もラベル付与もしない。
$unauthenticated = $kimuraMail;
$unauthenticated['payload']['headers'][1]['value'] = 'mx.google.com; dmarc=fail header.from=example.com';
foreach ([
    testMessage('ご注文を承りました', $kimuraBody, 'attacker@example.com'),
    $unauthenticated,
    testMessage('ご注文を承りました', str_replace('2026-09-21', '2026-02-30', $kimuraBody), 'kimura@example.com'),
    testMessage('ご注文を承りました', '日付だけで内容なし', 'kimura@example.com'),
    testMessage('ご注文を承りました', str_replace('2026-09-21', '2026-09-23', $kimuraBody), 'kimura@example.com'),
] as $invalidMail) {
    [$service, $gmail, $notion] = fixture(['kimura' => $invalidMail]);
    assertSame(1, $service->run()['errors']);
    assertSame([], $notion->updates);
    assertSame([], $gmail->labels);
}

// 松屋は引き続き確認メールで注文済、別の受付メールで受付済になる。
$matsuyaOrder = testMessage('フォームにご記入いただきありがとうございます', "[2026-09-22]\nお弁当券ナンバー: B1234\nメニュー: 牛めし\nサイズ: S\nその他の要望: なし", 'forms-receipts-noreply@google.com');
$matsuyaReceipt = testMessage('【松屋】お弁当注文受付確認', '9月22日のお弁当の注文を受け付けました。', 'receipt@example.com');
[$service, $gmail, $notion] = fixture(['matsuya-order' => $matsuyaOrder, 'kimura' => $kimuraMail]);
$result = $service->run();
assertSame(0, $result['errors']);
assertSame(1, $result['order_confirmation_success']);
assertSame(1, $result['receipt_success']);
assertSame('注文済', $notion->pages['2026-09-22']['properties']['状況']['select']['name']);
assertSame('S', $notion->pages['2026-09-22']['properties']['サイズ']['select']['name']);
assertSame('ticket-B1234', $notion->pages['2026-09-22']['properties']['お弁当チケット']['relation'][0]['id']);
assertSame(false, isset($notion->pages['2026-09-22']['properties']['受付確認メール']));
$gmail->messages['matsuya-receipt'] = $matsuyaReceipt;
assertSame(1, $service->run()['receipt_success']);
assertSame('受付済', $notion->pages['2026-09-22']['properties']['状況']['select']['name']);
assertSame(['状況', '受付確認メール'], array_keys($notion->updates[2][1]));

// 松屋の受付と同時に見つかっても上限1件を超えず、次回に残りを処理する。
[$service, $gmail, $notion] = fixture(['kimura' => $kimuraMail, 'matsuya-receipt' => $matsuyaReceipt], budget: 1);
assertSame(1, $service->run()['receipt_success']);
assertSame(['kimura' => 'processed'], $gmail->labels);
assertSame(1, $service->run()['receipt_success']);
assertSame(2, count($gmail->labels));

// 1件の失敗で松屋側の処理を止めない。
[$service, $gmail, $notion] = fixture(['kimura' => $unauthenticated, 'matsuya-order' => $matsuyaOrder, 'matsuya-receipt' => $matsuyaReceipt]);
$result = $service->run();
assertSame(1, $result['errors']);
assertSame(1, $result['order_confirmation_success']);
assertSame(1, $result['receipt_success']);
assertSame('受付済', $notion->pages['2026-09-22']['properties']['状況']['select']['name']);
assertSame(false, isset($gmail->labels['kimura']));

echo "LunchOrderService single-mail test passed\n";

function testMessage(string $subject, string $body, string $sender): array
{
    return [
        'subject' => $subject,
        'internalDate' => (string) (strtotime('2026-09-17 09:00:00') * 1000),
        'payload' => [
            'mimeType' => 'text/plain',
            'headers' => [
                ['name' => 'From', 'value' => $sender],
                ['name' => 'Authentication-Results', 'value' => 'mx.google.com; spf=pass smtp.mailfrom=' . $sender],
            ],
            'body' => ['data' => rtrim(strtr(base64_encode($body), '+/', '-_'), '=')],
        ],
    ];
}

function orderPage(string $date, ?string $shop = null, string $status = '未注文', ?string $orderUrl = null): array
{
    return ['id' => $date, 'properties' => [
        '日付' => ['date' => ['start' => $date]],
        'お店' => ['select' => $shop === null ? null : ['name' => $shop]],
        '状況' => ['select' => ['name' => $status]],
        '注文確認メール' => ['url' => $orderUrl],
    ]];
}

function fixture(array $messages, array $pages = [], int $budget = 100): array
{
    $turnPath = sys_get_temp_dir() . '/kimura-single-mail-' . bin2hex(random_bytes(8)) . '.state';
    register_shutdown_function(static fn () => is_file($turnPath) && unlink($turnPath));
    $gmail = new GmailClient($messages);
    $notion = new NotionClient($pages + [
        '2026-09-21' => orderPage('2026-09-21'),
        '2026-09-22' => orderPage('2026-09-22'),
    ]);
    $service = new LunchOrderService($gmail, $notion, new MailParser(), new Logger(), [
        'initial_record_days' => 0,
        'gmail_max_messages_per_run' => $budget,
        'gmail_receipt_turn_path' => $turnPath,
        'lookback_days' => 7,
        'gmail_processed_label_name' => 'processed',
        'matsuya_mail_order_from' => 'forms-receipts-noreply@google.com',
        'matsuya_mail_receipt_from' => 'receipt@example.com',
        'ramen_kimura_mail_order_from' => 'kimura@example.com',
        'ramen_kimura_mail_order_subject' => 'ご注文を承りました  ',
        'shop_name' => '松屋',
    ]);
    return [$service, $gmail, $notion];
}

function assertSame(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException('Assertion failed: expected=' . var_export($expected, true) . ', actual=' . var_export($actual, true));
    }
}
