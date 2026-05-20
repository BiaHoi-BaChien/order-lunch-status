<?php

declare(strict_types=1);

require_once __DIR__ . '/src/Logger.php';
require_once __DIR__ . '/src/GmailClient.php';
require_once __DIR__ . '/src/NotionClient.php';
require_once __DIR__ . '/src/MailParser.php';
require_once __DIR__ . '/src/LunchOrderService.php';
require_once __DIR__ . '/src/SlackNotifier.php';

$logger = null;

try {
    $config = require __DIR__ . '/config.php';
    date_default_timezone_set($config['timezone']);
    $logger = new Logger($config['log_file_path']);

    $logger->info('処理開始日時: ' . (new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM));
    $logger->info('ログ出力単位: ' . $config['log_output_unit']);

    $gmail = new GmailClient(
        $config['gmail_user_id'],
        $config['gmail_credentials_path'],
        $config['gmail_token_path'],
        $logger
    );

    $notion = new NotionClient(
        $config['notion_api_key'],
        $config['notion_order_data_source_id'],
        $config['notion_ticket_data_source_id']
    );

    $service = new LunchOrderService($gmail, $notion, new MailParser(), $logger, $config);
    $summary = $service->run();

    $logger->info('処理結果: ' . json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    if ($config['slack_notification_enabled'] && SlackNotifier::shouldNotifyResult($summary)) {
        try {
            (new SlackNotifier($config['slack_webhook_url'], $config['timezone']))->notifyResult($summary);
            $logger->info('Slack通知成功');
        } catch (Throwable $e) {
            $logger->error('Slack通知失敗: ' . $e->getMessage());
        }
    } elseif ($config['slack_notification_enabled']) {
        $logger->info('Slack通知スキップ: 更新なし');
    }
    $logger->info('処理終了日時: ' . (new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM));

    exit($summary['errors'] > 0 ? 1 : 0);
} catch (Throwable $e) {
    if ($logger instanceof Logger) {
        $logger->error('FATAL: ' . $e->getMessage());
    } else {
        fwrite(STDERR, 'FATAL: ' . $e->getMessage() . PHP_EOL);
    }
    exit(1);
}
