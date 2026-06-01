<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/SlackNotifier.php';

$notifier = new SlackNotifier('https://example.com/webhook', 'Asia/Ho_Chi_Minh');
$method = new ReflectionMethod(SlackNotifier::class, 'buildMessage');

$message = $method->invoke($notifier, [
    'initial_created' => 2,
    'order_confirmation_found' => 5,
    'order_confirmation_success' => 0,
    'order_confirmation_skipped' => 5,
    'receipt_found' => 5,
    'receipt_success' => 1,
    'receipt_skipped' => 4,
    'errors' => 0,
    'error_details' => [],
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

assertContains("直近の注文状況:\n2026/05/11(月) [受付済] 牛めし（A券：牛めし） [S] つゆだく、ネギ抜き", $message);
assertContains('2026/05/12(火) [注文済] 唐揚げ定食（B券：定食・丼） [S]', $message);
assertContains('2026/05/13(水) [未注文]', $message);
assertContains("処理内容:\n- 初期レコード作成: 2件", $message);
assertContains('- 注文受付メール更新: 1件', $message);
assertNotContains('お弁当注文状況バッチ 処理結果', $message);
assertNotContains('注文確認メール: 検索 5 / 成功 0 / スキップ 5', $message);
assertNotContains('注文確認メール更新: 0件', $message);
assertNotContains('注文受付メール: 検索 5 / 成功 1 / スキップ 4', $message);
assertNotContains('エラー: 0', $message);

$errorMessage = $method->invoke($notifier, [
    'initial_created' => 0,
    'order_confirmation_success' => 1,
    'receipt_success' => 0,
    'errors' => 1,
    'error_details' => [
        '注文確認メール処理失敗: message_id=abc123, 該当するチケットが見つかりません: ticket_no=A-001, order_date=2026-05-11',
    ],
    'recent_orders' => [
        [
            'date' => '2026-05-11',
            'weekday' => '月',
            'status' => '未注文',
            'item_name' => '',
            'size' => '',
            'note' => '',
        ],
    ],
]);

assertContains("直近の注文状況:\n2026/05/11(月) [未注文]", $errorMessage);
assertContains("処理内容:\n- 注文確認メール更新: 1件", $errorMessage);
assertNotContains('初期レコード作成: 0件', $errorMessage);
assertNotContains('注文受付メール更新: 0件', $errorMessage);
assertContains("エラー内容:\n- 注文確認メール処理失敗: message_id=abc123, 該当するチケットが見つかりません: ticket_no=A-001, order_date=2026-05-11", $errorMessage);

assertSame(false, SlackNotifier::shouldNotifyResult([
    'initial_created' => 0,
    'order_confirmation_success' => 0,
    'receipt_success' => 0,
    'errors' => 0,
]));
assertSame(true, SlackNotifier::shouldNotifyResult([
    'initial_created' => 0,
    'order_confirmation_success' => 0,
    'receipt_success' => 1,
    'errors' => 0,
]));
assertSame(true, SlackNotifier::shouldNotifyResult([
    'initial_created' => 0,
    'order_confirmation_success' => 0,
    'receipt_success' => 0,
    'errors' => 1,
]));

echo "SlackNotifier test passed\n";

function assertContains(string $expected, string $actual): void
{
    if (!str_contains($actual, $expected)) {
        throw new RuntimeException('Assertion failed: expected substring=' . $expected . ', actual=' . $actual);
    }
}

function assertNotContains(string $unexpected, string $actual): void
{
    if (str_contains($actual, $unexpected)) {
        throw new RuntimeException('Assertion failed: unexpected substring=' . $unexpected . ', actual=' . $actual);
    }
}

function assertSame(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException('Assertion failed: expected=' . var_export($expected, true) . ', actual=' . var_export($actual, true));
    }
}
