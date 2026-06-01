<?php

declare(strict_types=1);

final class LunchOrderService
{
    private const WEEKDAYS = ['日', '月', '火', '水', '木', '金', '土'];

    public function __construct(
        private readonly GmailClient $gmail,
        private readonly NotionClient $notion,
        private readonly MailParser $parser,
        private readonly NotionPropertyPayloadBuilder $propertyPayloadBuilder,
        private readonly Logger $logger,
        private readonly array $config
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function run(): array
    {
        $summary = [
            'initial_created' => 0,
            'order_confirmation_found' => 0,
            'order_confirmation_success' => 0,
            'order_confirmation_skipped' => 0,
            'order_confirmation_labeled' => 0,
            'receipt_found' => 0,
            'receipt_success' => 0,
            'receipt_skipped' => 0,
            'receipt_labeled' => 0,
            'errors' => 0,
            'error_details' => [],
        ];

        $summary['initial_created'] = $this->ensureInitialRecords();

        $orderMessages = $this->gmail->searchMessages($this->gmailSearchQuery(
            (string) ($this->config['mail_order_subject'] ?? 'フォームにご記入いただきありがとうございます'),
            (string) ($this->config['mail_order_from'] ?? 'forms-receipts-noreply@google.com')
        ));
        $summary['order_confirmation_found'] = count($orderMessages);
        $this->logger->info('注文確認メール検索件数: ' . count($orderMessages));

        foreach ($orderMessages as $messageRef) {
            try {
                $result = $this->processOrderConfirmation($messageRef['id']);
                $summary[$result === 'success' ? 'order_confirmation_success' : 'order_confirmation_skipped']++;
                if ($this->labelProcessedMessage($messageRef['id'])) {
                    $summary['order_confirmation_labeled']++;
                }
            } catch (Throwable $e) {
                $summary['errors']++;
                $errorDetail = "注文確認メール処理失敗: message_id={$messageRef['id']}, {$e->getMessage()}";
                $summary['error_details'][] = $errorDetail;
                $this->logger->error($errorDetail);
            }
        }

        $receiptMessages = $this->gmail->searchMessages($this->gmailSearchQuery(
            (string) ($this->config['mail_receipt_subject'] ?? '【松屋】お弁当注文受付確認')
        ));
        $summary['receipt_found'] = count($receiptMessages);
        $this->logger->info('注文受付メール検索件数: ' . count($receiptMessages));

        foreach ($receiptMessages as $messageRef) {
            try {
                $result = $this->processReceipt($messageRef['id']);
                $summary[$result === 'success' ? 'receipt_success' : 'receipt_skipped']++;
                if ($this->labelProcessedMessage($messageRef['id'])) {
                    $summary['receipt_labeled']++;
                }
            } catch (Throwable $e) {
                $summary['errors']++;
                $errorDetail = "注文受付メール処理失敗: message_id={$messageRef['id']}, {$e->getMessage()}";
                $summary['error_details'][] = $errorDetail;
                $this->logger->error($errorDetail);
            }
        }

        $this->logger->info('初期レコード作成件数: ' . $summary['initial_created']);
        $this->logger->info('注文確認メール処理成功件数: ' . $summary['order_confirmation_success']);
        $this->logger->info('注文確認メールスキップ件数: ' . $summary['order_confirmation_skipped']);
        $this->logger->info('注文確認メールラベル付与件数: ' . $summary['order_confirmation_labeled']);
        $this->logger->info('注文受付メール処理成功件数: ' . $summary['receipt_success']);
        $this->logger->info('注文受付メールスキップ件数: ' . $summary['receipt_skipped']);
        $this->logger->info('注文受付メールラベル付与件数: ' . $summary['receipt_labeled']);
        $this->logger->info('エラー件数: ' . $summary['errors']);
        $summary['recent_orders'] = $this->recentOrders(5);

        return $summary;
    }

    private function ensureInitialRecords(): int
    {
        $created = 0;
        $today = new DateTimeImmutable('today');
        $days = (int) $this->config['initial_record_days'];

        for ($i = 0; $i <= $days; $i++) {
            $date = $today->modify("+{$i} days");
            $dateText = $date->format('Y-m-d');
            if ($this->notion->findOrderByDate($dateText) !== null) {
                continue;
            }

            $weekday = $this->weekday($date);
            $status = in_array($weekday, ['土', '日'], true) ? '利用しない' : '未注文';
            $this->notion->createInitialOrder($dateText, $weekday, $status);
            $created++;
            $this->logger->info("初期レコード作成: date={$dateText}, weekday={$weekday}, status={$status}");
        }

        return $created;
    }

    private function gmailSearchQuery(string $subject, string $from = ''): string
    {
        $terms = [];
        if (trim($from) !== '') {
            $terms[] = 'from:' . trim($from);
        }
        $terms[] = 'subject:"' . str_replace('"', '\\"', $subject) . '"';
        $terms[] = sprintf('newer_than:%dd', (int) $this->config['lookback_days']);
        $processedLabelName = trim((string) ($this->config['gmail_processed_label_name'] ?? ''));
        if ($processedLabelName !== '') {
            $terms[] = '-label:"' . str_replace('"', '\\"', $processedLabelName) . '"';
        }

        return implode(' ', $terms);
    }

    private function labelProcessedMessage(string $messageId): bool
    {
        $labelName = trim((string) ($this->config['gmail_processed_label_name'] ?? ''));
        if ($labelName === '') {
            return false;
        }

        $this->gmail->addLabel($messageId, $labelName);
        $this->logger->info("Gmail処理済みラベル付与: label={$labelName}, message_id={$messageId}");

        return true;
    }

    private function processOrderConfirmation(string $messageId): string
    {
        $message = $this->gmail->getMessage($messageId);
        $order = $this->parser->parseOrderConfirmation($message);
        if ($order['warn_previous_year']) {
            $this->logger->warn("注文確認メールの日付が前年の可能性があります: date={$order['date']}, message_id={$messageId}");
        }
        $url = $this->gmail->messageUrl($messageId);

        $page = $this->notion->findOrderByDate($order['date']);
        if ($page === null) {
            throw new RuntimeException("更新対象の日付レコードがありません: date={$order['date']}");
        }

        $status = $this->selectName($page, '状況');
        if (in_array($status, ['注文済', '受付済'], true)) {
            $this->logger->info("既に処理済みのためスキップ: date={$order['date']}, status={$status}");
            return 'skipped';
        }
        if (!in_array($status, ['利用しない', '未注文', null], true)) {
            $this->logger->warn("想定外の状況を更新します: date={$order['date']}, current_status={$status}");
        }

        $ticket = $this->notion->findTicketByNumber($order['ticket_no']);
        if ($ticket === null || empty($ticket['id'])) {
            throw new RuntimeException("該当するチケットが見つかりません: ticket_no={$order['ticket_no']}, order_date={$order['date']}");
        }

        $date = new DateTimeImmutable($order['date']);
        $properties = [
            '品名' => ['title' => [['text' => ['content' => $order['item_name']]]]],
            '日付' => ['date' => ['start' => $order['date']]],
            '曜日' => ['select' => ['name' => $this->weekday($date)]],
            '状況' => ['select' => ['name' => '注文済']],
            'サイズ' => ['select' => ['name' => $order['size']]],
            'お店' => ['select' => ['name' => $this->config['shop_name']]],
            '備考' => ['rich_text' => $order['note'] === '' ? [] : [['text' => ['content' => $order['note']]]]],
            '注文確認メール' => ['url' => $url],
            'お弁当チケット' => ['relation' => [['id' => (string) $ticket['id']]]],
        ];
        $mappedProperties = $this->propertyPayloadBuilder->build(
            $this->config['mail_notion_property_mappings'] ?? [],
            $order['mapped_fields'] ?? []
        );
        foreach ($mappedProperties as $property => $payload) {
            if (!array_key_exists($property, $properties)) {
                $properties[$property] = $payload;
            }
        }

        $this->notion->updateOrder((string) $page['id'], $properties);

        $this->logger->info("注文確認メール処理成功: date={$order['date']}, ticket_no={$order['ticket_no']}, message_id={$messageId}");

        return 'success';
    }

    private function processReceipt(string $messageId): string
    {
        $message = $this->gmail->getMessage($messageId);
        $receipt = $this->parser->parseReceipt($message);
        if ($receipt['warn_previous_year']) {
            $this->logger->warn("受付メールの日付が前年の可能性があります: date={$receipt['date']}, message_id={$messageId}");
        }

        $page = $this->notion->findOrderByDate($receipt['date']);
        if ($page === null) {
            throw new RuntimeException("受付メールに対応する注文レコードなし: date={$receipt['date']}");
        }

        $url = $this->gmail->messageUrl($messageId);
        $currentUrl = $this->urlValue($page, '受付確認メール');
        if ($currentUrl === $url && $this->selectName($page, '状況') === '受付済') {
            $this->logger->info("受付確認メールは既に処理済みのためスキップ: date={$receipt['date']}, message_id={$messageId}");
            return 'skipped';
        }

        $status = $this->selectName($page, '状況');
        if (in_array($status, ['未注文', '利用しない'], true)) {
            $this->logger->warn("注文済でないレコードを受付済に更新: date={$receipt['date']}, current_status={$status}");
        }

        $this->notion->updateOrder((string) $page['id'], [
            '状況' => ['select' => ['name' => '受付済']],
            '受付確認メール' => ['url' => $url],
        ]);

        $this->logger->info("注文受付メール処理成功: date={$receipt['date']}, message_id={$messageId}");

        return 'success';
    }

    /**
     * @return list<array{date:string,weekday:string,status:string,item_name:string,size:string,note:string}>
     */
    private function recentOrders(int $days): array
    {
        $today = new DateTimeImmutable('today');
        $endDate = $today->modify("+{$days} days")->format('Y-m-d');
        $pages = $this->notion->findOrdersByDateRange($today->format('Y-m-d'), $endDate);
        $pagesByDate = [];
        foreach ($pages as $page) {
            $date = $this->dateValue($page, '日付');
            if ($date !== null) {
                $pagesByDate[$date] = $page;
            }
        }

        $orders = [];
        for ($i = 0; $i < $days; $i++) {
            $date = $today->modify("+{$i} days");
            $dateText = $date->format('Y-m-d');
            $page = $pagesByDate[$dateText] ?? null;
            $orders[] = [
                'date' => $dateText,
                'weekday' => $this->weekday($date),
                'status' => is_array($page) ? ($this->selectName($page, '状況') ?? '未設定') : '未登録',
                'item_name' => is_array($page) ? $this->titleValue($page, '品名') : '',
                'size' => is_array($page) ? ($this->selectName($page, 'サイズ') ?? '') : '',
                'note' => is_array($page) ? $this->richTextValue($page, '備考') : '',
            ];
        }

        return $orders;
    }

    private function weekday(DateTimeImmutable $date): string
    {
        return self::WEEKDAYS[(int) $date->format('w')];
    }

    private function selectName(array $page, string $property): ?string
    {
        return $page['properties'][$property]['select']['name'] ?? null;
    }

    private function dateValue(array $page, string $property): ?string
    {
        $value = $page['properties'][$property]['date']['start'] ?? null;
        if (!is_string($value)) {
            return null;
        }

        return substr($value, 0, 10);
    }

    private function titleValue(array $page, string $property): string
    {
        $value = '';
        foreach (($page['properties'][$property]['title'] ?? []) as $text) {
            $value .= $text['plain_text'] ?? $text['text']['content'] ?? '';
        }

        return $value;
    }

    private function richTextValue(array $page, string $property): string
    {
        $value = '';
        foreach (($page['properties'][$property]['rich_text'] ?? []) as $text) {
            $value .= $text['plain_text'] ?? $text['text']['content'] ?? '';
        }

        return $value;
    }

    private function urlValue(array $page, string $property): ?string
    {
        return $page['properties'][$property]['url'] ?? null;
    }
}
