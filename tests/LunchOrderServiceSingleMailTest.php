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
    public bool $ticketExists = true;

    public function __construct(public array $pages) {}
    public function findOrderByDate(string $date): ?array { return $this->pages[$date] ?? null; }
    public function findOrdersByDateRange(string $start, string $end): array { return array_values($this->pages); }
    public function createInitialOrder(string $date, string $weekday, string $status): array
    {
        return $this->pages[$date] = orderPage($date, null, $status);
    }
    public function findTicketByNumber(string $number): ?array { return $this->ticketExists ? ['id' => 'ticket-' . $number] : null; }
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
assertSame(4, count($gmail->queries));
assertSame('from:kimura@example.com subject:"ご注文を承りました" newer_than:7d -label:"processed"', $gmail->queries[1][0]);
assertSame(0, count($notion->images));

// QRなしでも、ラベルを外して再実行した同一メールは重複更新しない。
$gmail->labels = [];
$result = $service->run();
assertSame(1, $result['receipt_skipped']);
assertSame(1, count($notion->updates));
assertSame(1, $gmail->qrReads);

// 同日の複数商品は品名を連結して受付済へ更新し、成功したメールにラベルを付ける。
$multipleItemBody = str_replace('チャーハン唐揚げ弁当 × 1 80,000 VND', "チャーシュー丼 × 1 80,000 VND\n餃子（3個） × 1 25,000 VND", $kimuraBody);
$multipleItemBody = str_replace('お支払い 80,000 VND', 'お支払い 105,000 VND', $multipleItemBody);
[$service, $gmail, $notion] = fixture([
    'kimura' => testMessage('ご注文を承りました', $multipleItemBody, 'kimura@example.com'),
]);
$result = $service->run();
assertSame(0, $result['errors']);
assertSame(1, $result['receipt_success']);
$properties = $notion->pages['2026-09-21']['properties'];
assertSame('受付済', $properties['状況']['select']['name']);
assertSame('チャーシュー丼、餃子（3個）', $properties['品名']['title'][0]['text']['content']);
assertSame('合計金額: 105,000 VND', $properties['備考']['rich_text'][0]['text']['content']);
assertSame(['kimura' => 'processed'], $gmail->labels);

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

// 松屋も1通で受付済になり、チケット・サイズ・備考と両方のメールURLを登録する。
$matsuyaSubject = '【松屋フーズ】お弁当ご注文受付完了のお知らせ';
$matsuyaBody = "[2026-09-22]\nお弁当券ナンバー: B1234\nメニュー: 牛めし\nサイズ: S\nカスタマイズ: ネギ抜き\nその他の要望: なし";
$matsuyaOrder = testMessage($matsuyaSubject, $matsuyaBody, 'matsuya@example.com');
$matsuyaUrl = 'https://mail.google.com/mail/u/0/#all/matsuya-order';
$matsuyaReceipt = testMessage('【松屋】お弁当注文受付確認', '9月22日のお弁当の注文を受け付けました。', 'receipt@example.com');
[$service, $gmail, $notion] = fixture([
    'matsuya-order' => $matsuyaOrder,
    'matsuya-receipt' => $matsuyaReceipt,
    'old-matsuya-order' => testMessage('フォームにご記入いただきありがとうございます', $matsuyaBody, 'forms-receipts-noreply@google.com'),
    'kimura' => $kimuraMail,
]);
$result = $service->run();
assertSame(0, $result['errors']);
assertSame(1, $result['order_confirmation_found']);
assertSame(1, $result['order_confirmation_success']);
assertSame(1, $result['receipt_success']);
$properties = $notion->pages['2026-09-22']['properties'];
assertSame('受付済', $properties['状況']['select']['name']);
assertSame('松屋', $properties['お店']['select']['name']);
assertSame('牛めし', $properties['品名']['title'][0]['text']['content']);
assertSame('S', $properties['サイズ']['select']['name']);
assertSame('その他の要望: なし、カスタマイズ: ネギ抜き', $properties['備考']['rich_text'][0]['text']['content']);
assertSame('ticket-B1234', $properties['お弁当チケット']['relation'][0]['id']);
assertSame($matsuyaUrl, $properties['注文確認メール']['url']);
assertSame($matsuyaUrl, $properties['受付確認メール']['url']);
assertSame(['matsuya-order' => 'processed', 'kimura' => 'processed'], $gmail->labels);
assertSame(4, count($gmail->queries));
assertSame('from:matsuya@example.com subject:"' . $matsuyaSubject . '" newer_than:7d -label:"processed"', $gmail->queries[0][0]);

// ラベルを外しても同じ松屋メールは重複更新せず、旧受付メールは処理しない。
$gmail->labels = [];
$result = $service->run();
assertSame(1, $result['order_confirmation_skipped']);
assertSame(1, $result['receipt_skipped']);
assertSame(2, count($notion->updates));
assertSame(false, isset($gmail->labels['matsuya-receipt']));

// 旧処理の注文済、または旧受付URLがある受付済も、同じ注文メールなら移行する。
foreach (['注文済', '受付済'] as $status) {
    $page = orderPage('2026-09-22', '松屋', $status, $matsuyaUrl);
    $page['properties']['受付確認メール'] = ['url' => 'https://mail.google.com/mail/u/0/#all/old-receipt'];
    [$service, $gmail, $notion] = fixture(['matsuya-order' => $matsuyaOrder], ['2026-09-22' => $page]);
    assertSame(1, $service->run()['order_confirmation_success']);
    assertSame('受付済', $notion->pages['2026-09-22']['properties']['状況']['select']['name']);
    assertSame($matsuyaUrl, $notion->pages['2026-09-22']['properties']['受付確認メール']['url']);
}

// 松屋でも別店舗・別メールの注文は上書きせず、ラベル付与もしない。
foreach ([['松屋', '注文済', 'different'], ['松屋', '受付済', 'different'], ['RAMEN KIMURA', '受付済', $matsuyaUrl]] as [$shop, $status, $url]) {
    [$service, $gmail, $notion] = fixture(['matsuya-order' => $matsuyaOrder], [
        '2026-09-22' => orderPage('2026-09-22', $shop, $status, $url),
    ]);
    assertSame(1, $service->run()['errors']);
    assertSame([], $notion->updates);
    assertSame([], $gmail->labels);
}

// 松屋の送信元認証・本文検証・対象日付検証を維持する。
$matsuyaUnauthenticated = $matsuyaOrder;
$matsuyaUnauthenticated['payload']['headers'][1]['value'] = 'mx.google.com; dmarc=fail header.from=example.com';
foreach ([
    testMessage($matsuyaSubject, $matsuyaBody, 'attacker@example.com'),
    $matsuyaUnauthenticated,
    testMessage($matsuyaSubject, str_replace('2026-09-22', '2026-02-30', $matsuyaBody), 'matsuya@example.com'),
    testMessage($matsuyaSubject, '日付だけで内容なし', 'matsuya@example.com'),
    testMessage($matsuyaSubject, str_replace('2026-09-22', '2026-09-23', $matsuyaBody), 'matsuya@example.com'),
] as $invalidMail) {
    [$service, $gmail, $notion] = fixture(['matsuya-order' => $invalidMail]);
    assertSame(1, $service->run()['errors']);
    assertSame([], $notion->updates);
    assertSame([], $gmail->labels);
}

// チケットが見つからない場合は更新せず、更新失敗時もラベルを付けず再試行できる。
[$service, $gmail, $notion] = fixture(['matsuya-order' => $matsuyaOrder]);
$notion->ticketExists = false;
assertSame(1, $service->run()['errors']);
assertSame([], $notion->updates);
assertSame([], $gmail->labels);
$notion->ticketExists = true;
$notion->failNextUpdate = true;
assertSame(1, $service->run()['errors']);
assertSame([], $gmail->labels);
assertSame(1, $service->run()['order_confirmation_success']);
assertSame(1, count($notion->updates));

// 上限1件でも3店舗を順番に検索し、各メールを1回だけ処理する。
[$service, $gmail, $notion] = fixture(['kimura' => $kimuraMail, 'matsuya-order' => $matsuyaOrder], budget: 1);
assertSame(1, $service->run()['receipt_success']);
assertSame(['kimura' => 'processed'], $gmail->labels);
assertSame(1, count($gmail->queries));
assertSame(0, $service->run()['order_confirmation_success']);
assertSame(1, $service->run()['order_confirmation_success']);
assertSame(2, count($gmail->labels));
assertSame(4, count($gmail->queries));

// 1件の失敗で別店舗の処理を止めない（両方向）。
foreach ([[$matsuyaOrder, $unauthenticated], [$matsuyaUnauthenticated, $kimuraMail]] as [$matsuya, $kimura]) {
    [$service, $gmail, $notion] = fixture(['kimura' => $kimura, 'matsuya-order' => $matsuya]);
    $result = $service->run();
    assertSame(1, $result['errors']);
    assertSame(1, $result['order_confirmation_success'] + $result['receipt_success']);
    assertSame(1, count($notion->updates));
    assertSame(1, count($gmail->labels));
}

$kororiSubject = 'おむすびランチ ご注文・お支払いのご案内';
$kororiBody = "ご注文ありがとうございます。\n注文番号：M261114-0005\nお渡し希望日：11月18日（水）\n\n・おむすび 塩 × 1個（単価：22,000 VND）\n・おかずセット × 1個（単価：50,000 VND）\n\n合計金額：72,000 VND\n入金確認後に確認メールをお送りします。";
$kororiMail = testMessage($kororiSubject, $kororiBody, 'korori@example.com');
$kororiMail['internalDate'] = (string) (strtotime('2026-11-14 09:05:48') * 1000);
$kororiUrl = 'https://mail.google.com/mail/u/0/#all/korori';

// KORORIは注文メールだけでは注文済にし、件名が異なるメールは処理しない。
[$service, $gmail, $notion] = fixture([
    'korori' => $kororiMail,
    'korori-receipt' => testMessage('KORORI ご入金を確認しました', '未対応の確認メール', 'korori@example.com'),
]);
$result = $service->run();
assertSame(0, $result['errors']);
assertSame(1, $result['order_confirmation_found']);
assertSame(1, $result['order_confirmation_success']);
assertSame(1, $result['order_confirmation_labeled']);
assertSame(0, $result['receipt_found']);
$properties = $notion->pages['2026-11-18']['properties'];
assertSame('注文済', $properties['状況']['select']['name']);
assertSame('おむすび屋KORORI', $properties['お店']['select']['name']);
assertSame('おむすび 塩、おかずセット', $properties['品名']['title'][0]['text']['content']);
assertSame('水', $properties['曜日']['select']['name']);
assertSame('注文番号: M261114-0005、合計金額: 72,000 VND', $properties['備考']['rich_text'][0]['text']['content']);
assertSame($kororiUrl, $properties['注文確認メール']['url']);
assertSame(false, isset($properties['受付確認メール']));
assertSame(false, isset($properties['お弁当チケット']));
assertSame(['korori' => 'processed'], $gmail->labels);
assertSame('from:korori@example.com subject:"' . $kororiSubject . '" newer_than:7d -label:"processed"', $gmail->queries[2][0]);

// 同一メールの再処理はスキップし、受付済からも戻さない。
foreach (['注文済', '受付済'] as $status) {
    $gmail->labels = [];
    $notion->pages['2026-11-18']['properties']['状況']['select']['name'] = $status;
    assertSame(1, $service->run()['order_confirmation_skipped']);
    assertSame($status, $notion->pages['2026-11-18']['properties']['状況']['select']['name']);
    assertSame(1, count($notion->updates));
    assertSame(1, $gmail->qrReads);
}

// QR追加後の更新失敗も、ラベルを付けずに安全に再試行する。
[$service, $gmail, $notion] = fixture(['korori' => $kororiMail + ['qr' => ['data' => 'image', 'mime_type' => 'image/png']]]);
$notion->failNextUpdate = true;
assertSame(1, $service->run()['errors']);
assertSame([], $gmail->labels);
assertSame(1, $service->run()['order_confirmation_success']);
assertSame(1, count($notion->images['2026-11-18']));
assertSame('注文済', $notion->pages['2026-11-18']['properties']['状況']['select']['name']);

foreach ([['松屋', '受付済', $kororiUrl], ['おむすび屋KORORI', '注文済', 'different']] as [$shop, $status, $url]) {
    [$service, $gmail, $notion] = fixture(['korori' => $kororiMail], [
        '2026-11-18' => orderPage('2026-11-18', $shop, $status, $url),
    ]);
    assertSame(1, $service->run()['errors']);
    assertSame([], $notion->updates);
    assertSame([], $gmail->labels);
    assertSame(0, $gmail->qrReads);
}

$kororiUnauthenticated = $kororiMail;
$kororiUnauthenticated['payload']['headers'][1]['value'] = 'mx.google.com; dmarc=fail header.from=example.com';
$kororiWrongSender = $kororiMail;
$kororiWrongSender['payload']['headers'][0]['value'] = 'attacker@example.com';
$kororiMissingDate = $kororiMail;
$kororiMissingDate['payload']['body']['data'] = rtrim(strtr(base64_encode(str_replace('11月18日', '11月19日', $kororiBody)), '+/', '-_'), '=');
$kororiInvalidBody = $kororiMail;
$kororiInvalidBody['payload']['body']['data'] = base64_encode('入金確認後に確認メールをお送りします。');
foreach ([$kororiUnauthenticated, $kororiWrongSender, $kororiMissingDate, $kororiInvalidBody] as $invalidMail) {
    [$service, $gmail, $notion] = fixture(['korori' => $invalidMail]);
    assertSame(1, $service->run()['errors']);
    assertSame([], $notion->updates);
    assertSame([], $gmail->labels);
    assertSame(0, $gmail->qrReads);
}

// 3店舗が混在しても、失敗した店舗以外の処理を継続する。
[$service, $gmail, $notion] = fixture(['matsuya-order' => $matsuyaOrder, 'kimura' => $kimuraMail, 'korori' => $kororiUnauthenticated]);
$result = $service->run();
assertSame(1, $result['errors']);
assertSame(2, count($notion->updates));
assertSame(2, count($gmail->labels));

// 検索上限1件でもKORORIに順番が回る。
[$service, $gmail, $notion] = fixture(['matsuya-order' => $matsuyaOrder, 'kimura' => $kimuraMail, 'korori' => $kororiMail], budget: 1);
assertSame(1, $service->run()['receipt_success']);
assertSame(1, $service->run()['order_confirmation_success']);
assertSame(['kimura' => 'processed', 'korori' => 'processed'], $gmail->labels);
assertSame(1, $service->run()['order_confirmation_success']);
assertSame(3, count($gmail->labels));
assertSame(3, count($gmail->queries));

$kororiReceiptSubject = 'おむすびランチ お支払い確認のお知らせ';
$kororiReceiptBody = "テスト利用者 様\n\nお支払いを確認いたしました。\n注文番号：M261114-0005\nお渡し希望日：11月18日（水）\nお支払い金額：72,000 VND\n\nご注文ありがとうございました。";
$kororiReceipt = testMessage($kororiReceiptSubject, $kororiReceiptBody, 'korori@example.com');
$kororiReceipt['internalDate'] = (string) (strtotime('2026-11-16 09:04:43') * 1000);
$kororiReceiptUrl = 'https://mail.google.com/mail/u/0/#all/korori-receipt';

// 新しい確認メールが先に検索されても、注文を登録してから受付済に進める。
[$service, $gmail, $notion] = fixture(['korori-receipt' => $kororiReceipt, 'korori' => $kororiMail]);
$result = $service->run();
assertSame(0, $result['errors']);
assertSame(1, $result['order_confirmation_success']);
assertSame(1, $result['receipt_found']);
assertSame(1, $result['receipt_success']);
assertSame(1, $result['receipt_labeled']);
$properties = $notion->pages['2026-11-18']['properties'];
assertSame('受付済', $properties['状況']['select']['name']);
assertSame($kororiUrl, $properties['注文確認メール']['url']);
assertSame($kororiReceiptUrl, $properties['受付確認メール']['url']);
assertSame('おむすび 塩、おかずセット', $properties['品名']['title'][0]['text']['content']);
assertSame('注文番号: M261114-0005、合計金額: 72,000 VND', $properties['備考']['rich_text'][0]['text']['content']);
assertSame(['状況' => ['select' => ['name' => '受付済']], '受付確認メール' => ['url' => $kororiReceiptUrl]], $notion->updates[1][1]);
assertSame(['korori' => 'processed', 'korori-receipt' => 'processed'], $gmail->labels);
assertSame(1, $gmail->qrReads);
assertSame(['from:korori@example.com subject:"' . $kororiReceiptSubject . '" newer_than:7d -label:"processed"', 32], $gmail->queries[3]);

// 既存注文の品名や備考は保持し、同一確認メールは再更新しない。
$registeredPage = $notion->pages['2026-11-18'];
$registeredPage['properties']['状況']['select']['name'] = '注文済';
unset($registeredPage['properties']['受付確認メール']);
[$service, $gmail, $notion] = fixture(['korori-receipt' => $kororiReceipt], ['2026-11-18' => $registeredPage]);
assertSame(1, $service->run()['receipt_success']);
$gmail->labels = [];
assertSame(1, $service->run()['receipt_skipped']);
assertSame(1, count($notion->updates));
assertSame(['korori-receipt' => 'processed'], $gmail->labels);
assertSame(0, $gmail->qrReads);

// 別店舗、別注文、注文未登録、既に別メールで受付済のレコードは変更しない。
$invalidPages = [orderPage('2026-11-18')];
foreach ([
    ['お店', ['select' => ['name' => '松屋']]],
    ['備考', ['rich_text' => []]],
    ['備考', ['rich_text' => [['text' => ['content' => '注文番号: M261114-00050、合計金額: 72,000 VND']]]]],
    ['状況', ['select' => ['name' => '利用しない']]],
    ['状況', ['select' => ['name' => '受付済']]],
] as [$property, $value]) {
    $page = $registeredPage;
    $page['properties'][$property] = $value;
    $invalidPages[] = $page;
}
foreach ($invalidPages as $page) {
    [$service, $gmail, $notion] = fixture(['korori-receipt' => $kororiReceipt], ['2026-11-18' => $page]);
    assertSame(1, $service->run()['errors']);
    assertSame([], $notion->updates);
    assertSame([], $gmail->labels);
}

$unauthenticatedReceipt = $kororiReceipt;
$unauthenticatedReceipt['payload']['headers'][1]['value'] = 'mx.google.com; dmarc=fail header.from=example.com';
$wrongSenderReceipt = $kororiReceipt;
$wrongSenderReceipt['payload']['headers'][0]['value'] = 'attacker@example.com';
$missingDateReceipt = $kororiReceipt;
$missingDateReceipt['payload']['body']['data'] = base64_encode(str_replace('11月18日', '11月19日', $kororiReceiptBody));
foreach ([$unauthenticatedReceipt, $wrongSenderReceipt, $missingDateReceipt] as $invalidMail) {
    [$service, $gmail, $notion] = fixture(['korori-receipt' => $invalidMail], ['2026-11-18' => $registeredPage]);
    assertSame(1, $service->run()['errors']);
    assertSame([], $notion->updates);
    assertSame([], $gmail->labels);
    assertSame(0, $gmail->qrReads);
}

// 更新失敗はラベルを付けずに再試行し、後続の確認メールは処理を継続する。
[$service, $gmail, $notion] = fixture(['korori-receipt' => $kororiReceipt], ['2026-11-18' => $registeredPage]);
$notion->failNextUpdate = true;
assertSame(1, $service->run()['errors']);
assertSame([], $gmail->labels);
assertSame(1, $service->run()['receipt_success']);
assertSame(1, count($notion->updates));
[$service, $gmail, $notion] = fixture([
    'invalid-receipt' => $unauthenticatedReceipt,
    'korori-receipt' => $kororiReceipt,
], ['2026-11-18' => $registeredPage]);
$result = $service->run();
assertSame(1, $result['errors']);
assertSame(1, $result['receipt_success']);
assertSame(['korori-receipt' => 'processed'], $gmail->labels);

// 少ない処理枠でも注文と確認を順に取り込み、全店舗の合計上限を守る。
foreach ([1, 2] as $budget) {
    [$service, $gmail, $notion] = fixture(['korori-receipt' => $kororiReceipt, 'korori' => $kororiMail], budget: $budget);
    for ($run = 0; $run < 6; $run++) {
        $result = $service->run();
        assertSame(0, $result['errors']);
        assertSame(true, $result['order_confirmation_found'] + $result['receipt_found'] <= $budget);
    }
    assertSame('受付済', $notion->pages['2026-11-18']['properties']['状況']['select']['name']);
    assertSame(2, count($notion->updates));
    assertSame(['korori' => 'processed', 'korori-receipt' => 'processed'], $gmail->labels);
}

// ラベル無効でも、注文メールの再検索で確認メールの処理枠を使い切らない。
[$service, $gmail, $notion] = fixture(['korori-receipt' => $kororiReceipt, 'korori' => $kororiMail], budget: 1, processedLabel: '');
for ($run = 0; $run < 12; $run++) {
    $result = $service->run();
    assertSame(0, $result['errors']);
    assertSame(true, $result['order_confirmation_found'] + $result['receipt_found'] <= 1);
}
assertSame('受付済', $notion->pages['2026-11-18']['properties']['状況']['select']['name']);
assertSame(2, count($notion->updates));
assertSame([], $gmail->labels);

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

function fixture(array $messages, array $pages = [], int $budget = 100, string $processedLabel = 'processed'): array
{
    $turnPath = sys_get_temp_dir() . '/kimura-single-mail-' . bin2hex(random_bytes(8)) . '.state';
    register_shutdown_function(static fn () => is_file($turnPath) && unlink($turnPath));
    register_shutdown_function(static fn () => is_file($turnPath . '.korori.state') && unlink($turnPath . '.korori.state'));
    $gmail = new GmailClient($messages);
    $notion = new NotionClient($pages + [
        '2026-09-21' => orderPage('2026-09-21'),
        '2026-09-22' => orderPage('2026-09-22'),
        '2026-11-18' => orderPage('2026-11-18'),
    ]);
    $service = new LunchOrderService($gmail, $notion, new MailParser(), new Logger(), [
        'initial_record_days' => 0,
        'gmail_max_messages_per_run' => $budget,
        'gmail_receipt_turn_path' => $turnPath,
        'lookback_days' => 7,
        'gmail_processed_label_name' => $processedLabel,
        'matsuya_mail_order_from' => 'matsuya@example.com',
        'ramen_kimura_mail_order_from' => 'kimura@example.com',
        'korori_mail_order_from' => 'korori@example.com',
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
