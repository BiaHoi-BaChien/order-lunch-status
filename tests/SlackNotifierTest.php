<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/SlackNotifier.php';

$notifier = new SlackNotifier('https://example.com/webhook', 'Asia/Ho_Chi_Minh');
$method = new ReflectionMethod(SlackNotifier::class, 'buildMessage');
$method->setAccessible(true);

$message = $method->invoke($notifier, [
    'initial_created' => 0,
    'order_confirmation_found' => 5,
    'order_confirmation_success' => 0,
    'order_confirmation_skipped' => 5,
    'receipt_found' => 5,
    'receipt_success' => 1,
    'receipt_skipped' => 4,
    'errors' => 0,
    'recent_orders' => [
        [
            'date' => '2026-05-11',
            'weekday' => '月',
            'status' => '受付済',
            'item_name' => '牛めし（A券：牛めし）',
            'size' => 'S',
            'note' => 'つゆだく、ネギ抜き',
        ],
        [
            'date' => '2026-05-12',
            'weekday' => '火',
            'status' => '注文済',
            'item_name' => '唐揚げ定食（B券：定食・丼）',
            'size' => 'S',
            'note' => '',
        ],
        [
            'date' => '2026-05-13',
            'weekday' => '水',
            'status' => '未注文',
            'item_name' => '',
            'size' => '',
            'note' => '',
        ],
    ],
]);

assertContains('お弁当注文状況バッチ 処理結果: 正常終了', $message);
assertContains('注文確認メール: 検索 5 / 成功 0 / スキップ 5', $message);
assertContains('注文受付メール: 検索 5 / 成功 1 / スキップ 4', $message);
assertContains("直近の注文状況:\n2026/05/11(月) [受付済] 牛めし（A券：牛めし） [S] つゆだく、ネギ抜き", $message);
assertContains('2026/05/12(火) [注文済] 唐揚げ定食（B券：定食・丼） [S]', $message);
assertContains('2026/05/13(水) [未注文]', $message);

echo "SlackNotifier test passed\n";

function assertContains(string $expected, string $actual): void
{
    if (!str_contains($actual, $expected)) {
        throw new RuntimeException('Assertion failed: expected substring=' . $expected . ', actual=' . $actual);
    }
}
