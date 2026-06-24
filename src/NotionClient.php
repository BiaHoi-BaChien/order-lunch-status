<?php

declare(strict_types=1);

final class NotionClient
{
    private const NOTION_VERSION = '2026-03-11';

    public function __construct(
        private readonly string $apiKey,
        private readonly string $orderDataSourceId,
        private readonly string $ticketDataSourceId,
        private readonly ?string $caBundlePath = null
    ) {
    }

    public function findOrderByDate(string $date): ?array
    {
        $result = $this->queryDataSource($this->orderDataSourceId, [
            'and' => [
                ['property' => '日付', 'date' => ['on_or_after' => $date]],
                ['property' => '日付', 'date' => ['before' => (new DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d')]],
            ],
        ]);

        return $result[0] ?? null;
    }

    public function findOrdersByDateRange(string $startDate, string $endDate): array
    {
        return $this->queryDataSource(
            $this->orderDataSourceId,
            [
                'and' => [
                    ['property' => '日付', 'date' => ['on_or_after' => $startDate]],
                    ['property' => '日付', 'date' => ['before' => $endDate]],
                ],
            ],
            [
                [
                    'property' => '日付',
                    'direction' => 'ascending',
                ],
            ]
        );
    }

    public function createInitialOrder(string $date, string $weekday, string $status): array
    {
        return $this->request('POST', '/pages', [
            'parent' => ['data_source_id' => $this->orderDataSourceId],
            'properties' => [
                '品名' => ['title' => [['text' => ['content' => '未注文']]]],
                '日付' => ['date' => ['start' => $date]],
                '曜日' => ['select' => ['name' => $weekday]],
                '状況' => ['select' => ['name' => $status]],
            ],
        ]);
    }

    public function findTicketByNumber(string $ticketNo): ?array
    {
        $result = $this->queryDataSource($this->ticketDataSourceId, [
            'property' => 'チケット番号',
            'title' => ['equals' => $ticketNo],
        ]);

        return $result[0] ?? null;
    }

    public function updateOrder(string $pageId, array $properties): array
    {
        return $this->request('PATCH', '/pages/' . rawurlencode($pageId), ['properties' => $properties]);
    }

    private function queryDataSource(string $dataSourceId, array $filter, array $sorts = []): array
    {
        $pages = [];
        $startCursor = null;

        do {
            $payload = ['filter' => $filter, 'page_size' => 100];
            if ($sorts !== []) {
                $payload['sorts'] = $sorts;
            }
            if ($startCursor !== null) {
                $payload['start_cursor'] = $startCursor;
            }

            $response = $this->request('POST', '/data_sources/' . rawurlencode($dataSourceId) . '/query', $payload);
            foreach (($response['results'] ?? []) as $page) {
                if (is_array($page)) {
                    $pages[] = $page;
                }
            }
            $startCursor = $response['next_cursor'] ?? null;
        } while (($response['has_more'] ?? false) === true && $startCursor !== null);

        return $pages;
    }

    private function request(string $method, string $path, array $payload): array
    {
        $ch = curl_init('https://api.notion.com/v1' . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
                'Notion-Version: ' . self::NOTION_VERSION,
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        CurlSupport::applyCaBundle($ch, $this->caBundlePath);

        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);

        if ($body === false) {
            throw new RuntimeException("Notion API通信失敗: {$error}");
        }
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException("Notion APIエラー: status={$status}, error={$this->apiErrorSummary((string) $body)}");
        }

        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Notion APIレスポンスJSONの解析に失敗しました');
        }

        return $decoded;
    }

    private function apiErrorSummary(string $body): string
    {
        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            return $this->truncateErrorSummary(implode(' / ', array_filter([
                $decoded['code'] ?? null,
                $decoded['status'] ?? null,
                $decoded['message'] ?? null,
            ], static fn ($value): bool => is_scalar($value) && (string) $value !== '')));
        }

        return 'レスポンス本文はログ出力しません';
    }

    private function truncateErrorSummary(string $summary): string
    {
        $summary = trim(preg_replace('/\s+/u', ' ', $summary) ?? $summary);
        if ($summary === '') {
            return '詳細なし';
        }

        return mb_strlen($summary, 'UTF-8') > 200 ? mb_substr($summary, 0, 200, 'UTF-8') . '...' : $summary;
    }
}
