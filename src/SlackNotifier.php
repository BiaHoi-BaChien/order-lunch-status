<?php

declare(strict_types=1);

final class SlackNotifier
{
    public function __construct(
        private readonly string $webhookUrl,
        private readonly string $timezone
    ) {
    }

    /**
     * @param array<string, mixed> $summary
     */
    public static function shouldNotifyResult(array $summary): bool
    {
        return ((int) ($summary['errors'] ?? 0)) > 0
            || ((int) ($summary['initial_created'] ?? 0)) > 0
            || ((int) ($summary['order_confirmation_success'] ?? 0)) > 0
            || ((int) ($summary['receipt_success'] ?? 0)) > 0;
    }

    /**
     * @param array<string, mixed> $summary
     */
    public function notifyResult(array $summary): void
    {
        $payload = [
            'text' => $this->buildMessage($summary),
        ];

        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            throw new RuntimeException('Slack通知ペイロードのJSON化に失敗しました');
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => [
                    'Content-Type: application/json; charset=utf-8',
                    'Content-Length: ' . strlen($body),
                ],
                'content' => $body,
                'ignore_errors' => true,
                'timeout' => 10,
            ],
        ]);

        $response = @file_get_contents($this->webhookUrl, false, $context);
        $statusCode = $this->statusCode($http_response_header ?? []);
        if ($response === false || $statusCode < 200 || $statusCode >= 300) {
            throw new RuntimeException("Slack通知に失敗しました: http_status={$statusCode}");
        }
    }

    /**
     * @param array<string, mixed> $summary
     */
    private function buildMessage(array $summary): string
    {
        $lines = [];

        if (!empty($summary['recent_orders']) && is_array($summary['recent_orders'])) {
            $lines[] = '直近の注文状況:';
            foreach ($summary['recent_orders'] as $order) {
                if (is_array($order)) {
                    $lines[] = $this->formatRecentOrder($order);
                }
            }
        }

        $processedLines = $this->processedLines($summary);
        if ($processedLines !== []) {
            if ($lines !== []) {
                $lines[] = '';
            }
            $lines[] = '処理内容:';
            foreach ($processedLines as $processedLine) {
                $lines[] = '- ' . $processedLine;
            }
        }

        if (((int) ($summary['errors'] ?? 0)) > 0) {
            if ($lines !== []) {
                $lines[] = '';
            }
            $lines[] = 'エラー内容:';
            $errorDetails = $summary['error_details'] ?? [];
            if (is_array($errorDetails) && $errorDetails !== []) {
                foreach ($errorDetails as $errorDetail) {
                    if (is_scalar($errorDetail) && (string) $errorDetail !== '') {
                        $lines[] = '- ' . (string) $errorDetail;
                    }
                }
            } else {
                $lines[] = '- 詳細不明のエラーが発生しました';
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $summary
     * @return list<string>
     */
    private function processedLines(array $summary): array
    {
        $lines = [];
        $initialCreated = (int) ($summary['initial_created'] ?? 0);
        if ($initialCreated > 0) {
            $lines[] = "初期レコード作成: {$initialCreated}件";
        }

        $orderConfirmationSuccess = (int) ($summary['order_confirmation_success'] ?? 0);
        if ($orderConfirmationSuccess > 0) {
            $lines[] = "注文確認メール更新: {$orderConfirmationSuccess}件";
        }

        $receiptSuccess = (int) ($summary['receipt_success'] ?? 0);
        if ($receiptSuccess > 0) {
            $lines[] = "注文受付メール更新: {$receiptSuccess}件";
        }

        return $lines;
    }

    /**
     * @param array<string, string> $order
     */
    private function formatRecentOrder(array $order): string
    {
        $date = DateTimeImmutable::createFromFormat('Y-m-d', $order['date'] ?? '');
        $dateText = $date instanceof DateTimeImmutable
            ? $date->format('Y/m/d')
            : ($order['date'] ?? '');
        $weekday = $order['weekday'] ?? '';
        $status = $order['status'] ?? '未設定';
        $parts = [
            "{$dateText}({$weekday})",
            "[{$status}]",
        ];

        if (($order['item_name'] ?? '') !== '') {
            $parts[] = $order['item_name'];
        }
        if (($order['size'] ?? '') !== '') {
            $parts[] = '[' . $order['size'] . ']';
        }
        if (($order['note'] ?? '') !== '') {
            $parts[] = $order['note'];
        }

        return implode(' ', $parts);
    }

    /**
     * @param list<string> $headers
     */
    private function statusCode(array $headers): int
    {
        if ($headers === []) {
            return 0;
        }

        if (preg_match('/^HTTP\/\S+\s+(\d{3})\b/', $headers[0], $matches) !== 1) {
            return 0;
        }

        return (int) $matches[1];
    }
}
