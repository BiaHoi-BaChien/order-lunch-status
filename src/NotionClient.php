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

    public function hasImageCaption(string $pageId, string $caption): bool
    {
        $startCursor = null;
        do {
            $params = ['page_size' => 100];
            if ($startCursor !== null) {
                $params['start_cursor'] = $startCursor;
            }
            $response = $this->request(
                'GET',
                '/blocks/' . rawurlencode($pageId) . '/children?' . http_build_query($params)
            );
            if ($this->blocksContainCaption($response['results'] ?? [], $caption)) {
                return true;
            }
            $startCursor = $response['next_cursor'] ?? null;
        } while (($response['has_more'] ?? false) === true && is_string($startCursor));

        return false;
    }

    public function appendImageIfMissing(string $pageId, string $data, string $mimeType, string $caption): bool
    {
        if ($this->hasImageCaption($pageId, $caption)) {
            return false;
        }
        if ($data === '' || strlen($data) > 20 * 1024 * 1024) {
            throw new RuntimeException('Notionへ追加するQR画像は1バイト以上20MB以下にしてください');
        }

        $mimeType = strtolower($mimeType) === 'image/jpg' ? 'image/jpeg' : strtolower($mimeType);
        $extensions = [
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
        ];
        if (!isset($extensions[$mimeType])) {
            throw new RuntimeException("Notionへ追加できないQR画像形式です: {$mimeType}");
        }

        $filename = 'ramen-kimura-qr.' . $extensions[$mimeType];
        $upload = $this->request('POST', '/file_uploads', [
            'mode' => 'single_part',
            'filename' => $filename,
            'content_type' => $mimeType,
        ]);
        $uploadId = (string) ($upload['id'] ?? '');
        if ($uploadId === '') {
            throw new RuntimeException('Notion File Upload IDを取得できません');
        }
        $this->sendFileUpload($uploadId, $data, $mimeType, $filename);

        $this->request('PATCH', '/blocks/' . rawurlencode($pageId) . '/children', [
            'children' => [[
                'type' => 'image',
                'image' => [
                    'caption' => [['type' => 'text', 'text' => ['content' => $caption]]],
                    'type' => 'file_upload',
                    'file_upload' => ['id' => $uploadId],
                ],
            ]],
        ]);

        return true;
    }

    public function appendExternalImageIfMissing(string $pageId, string $url, string $caption): bool
    {
        if ($this->hasImageCaption($pageId, $caption)) {
            return false;
        }
        if (filter_var($url, FILTER_VALIDATE_URL) === false || parse_url($url, PHP_URL_SCHEME) !== 'https') {
            throw new RuntimeException('Notionへ追加する外部QR画像URLは有効なHTTPS URLにしてください');
        }

        $this->request('PATCH', '/blocks/' . rawurlencode($pageId) . '/children', [
            'children' => [[
                'type' => 'image',
                'image' => [
                    'caption' => [['type' => 'text', 'text' => ['content' => $caption]]],
                    'type' => 'external',
                    'external' => ['url' => $url],
                ],
            ]],
        ]);

        return true;
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

    private function request(string $method, string $path, ?array $payload = null): array
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
        ]);
        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        if ($this->caBundlePath !== null) {
            curl_setopt($ch, CURLOPT_CAINFO, $this->caBundlePath);
        }

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

    private function sendFileUpload(string $uploadId, string $data, string $mimeType, string $filename): void
    {
        $ch = curl_init('https://api.notion.com/v1/file_uploads/' . rawurlencode($uploadId) . '/send');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey,
                'Accept: application/json',
                'Notion-Version: ' . self::NOTION_VERSION,
            ],
            CURLOPT_POSTFIELDS => ['file' => new CURLStringFile($data, $mimeType, $filename)],
        ]);
        if ($this->caBundlePath !== null) {
            curl_setopt($ch, CURLOPT_CAINFO, $this->caBundlePath);
        }

        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        if ($body === false) {
            throw new RuntimeException("Notion File Upload通信失敗: {$error}");
        }
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException("Notion File Uploadエラー: status={$status}, error={$this->apiErrorSummary((string) $body)}");
        }

        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded) || ($decoded['status'] ?? null) !== 'uploaded') {
            throw new RuntimeException('Notion File Uploadがuploadedになりませんでした');
        }
    }

    private function blocksContainCaption(array $blocks, string $caption): bool
    {
        foreach ($blocks as $block) {
            if (!is_array($block) || ($block['type'] ?? null) !== 'image') {
                continue;
            }
            foreach (($block['image']['caption'] ?? []) as $text) {
                if (($text['plain_text'] ?? $text['text']['content'] ?? null) === $caption) {
                    return true;
                }
            }
        }

        return false;
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
