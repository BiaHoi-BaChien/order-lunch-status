<?php

declare(strict_types=1);

// 実際の設定・起動処理を一時ディレクトリで実行し、サービス呼び出し直前まで検証する。
$fixtureDir = sys_get_temp_dir() . '/order-lunch-status-bootstrap-' . bin2hex(random_bytes(8));
mkdir($fixtureDir . '/src', 0777, true);
$fixtureFiles = [];

try {
    $sourcePaths = array_merge(
        [__DIR__ . '/../batch_lunch_order.php', __DIR__ . '/../config.php'],
        glob(__DIR__ . '/../src/*.php') ?: []
    );
    foreach ($sourcePaths as $sourcePath) {
        $relativePath = basename(dirname($sourcePath)) === 'src' ? 'src/' . basename($sourcePath) : basename($sourcePath);
        $targetPath = $fixtureDir . '/' . $relativePath;
        if (!copy($sourcePath, $targetPath)) {
            throw new RuntimeException('Cannot copy bootstrap fixture: ' . $relativePath);
        }
        $fixtureFiles[] = $targetPath;
    }

    // バッチ・config・クライアント生成は実コードを使い、外部処理を始めるサービスだけ置換する。
    file_put_contents($fixtureDir . '/src/LunchOrderService.php', <<<'PHP'
<?php
final class LunchOrderService
{
    public function __construct(GmailClient $gmail, NotionClient $notion, MailParser $parser, Logger $logger, array $config) {}

    public function run(): array
    {
        echo "BATCH_SERVICE_STARTED\n";
        return ['errors' => 0];
    }
}
PHP);

    $baseSettings = [
        'NOTION_API_KEY' => 'test-api-key',
        'NOTION_ORDER_DATA_SOURCE_ID' => 'test-orders',
        'NOTION_TICKET_DATA_SOURCE_ID' => 'test-tickets',
        'MAIL_MATSUYA_ORDER_FROM' => 'forms-receipts-noreply@google.com',
        'MAIL_MATSUYA_RECEIPT_FROM' => 'matsuya@example.com',
        'MAIL_RAMEN_KIMURA_ORDER_FROM' => 'kimura@example.com',
        'RUN_WINDOW_ENABLED' => 'false',
        'SLACK_NOTIFICATION_ENABLED' => 'false',
        'LOG_OUTPUT_UNIT' => 'single',
    ];
    $fixtureFiles[] = $fixtureDir . '/.env';
    $fixtureFiles[] = $fixtureDir . '/logs/lunch_batch.log';
    $fixtureFiles[] = $fixtureDir . '/logs/lunch_batch.lock';

    // 開発環境の認証情報やアプリ設定を子プロセスに引き継がない。
    $environment = [];
    foreach (['PATH', 'SystemRoot', 'TEMP', 'TMP', 'TMPDIR'] as $key) {
        $value = getenv($key);
        if ($value !== false) {
            $environment[$key] = $value;
        }
    }

    foreach ([[], ['MAIL_RAMEN_KIMURA_RECEIPT_FROM' => 'old-receipt@example.com']] as $legacySettings) {
        [$exitCode, $output] = runBatch($fixtureDir, $baseSettings + $legacySettings, $environment);
        assertSame(0, $exitCode, $output);
        assertSame(true, str_contains($output, 'BATCH_SERVICE_STARTED'), $output);
        assertSame(true, str_contains($output, '処理終了日時:'), $output);
    }

    // 現在も必要な設定が空の場合は、サービスを呼ぶ前に必ず停止する。
    foreach (array_keys($baseSettings) as $requiredKey) {
        if (!str_starts_with($requiredKey, 'NOTION_') && !str_starts_with($requiredKey, 'MAIL_')) {
            continue;
        }
        $settings = array_replace($baseSettings, [$requiredKey => '']);
        [$exitCode, $output] = runBatch($fixtureDir, $settings, $environment);
        assertSame(1, $exitCode, $output);
        assertSame(false, str_contains($output, 'BATCH_SERVICE_STARTED'), $output);
        assertSame(true, str_contains($output, "環境変数 {$requiredKey} が未設定です"), $output);
    }

    echo "Batch bootstrap test passed\n";
} finally {
    foreach ($fixtureFiles as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
    foreach ([$fixtureDir . '/logs', $fixtureDir . '/src', $fixtureDir] as $path) {
        if (is_dir($path)) {
            rmdir($path);
        }
    }
}

/** @return array{int, string} */
function runBatch(string $fixtureDir, array $settings, array $environment): array
{
    $lines = [];
    foreach ($settings as $key => $value) {
        $lines[] = $key . '=' . $value;
    }
    file_put_contents($fixtureDir . '/.env', implode(PHP_EOL, $lines) . PHP_EOL);

    $process = proc_open([
        PHP_BINARY,
        '-d', 'allow_url_fopen=0',
        '-d', 'disable_functions=curl_exec,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client',
        $fixtureDir . '/batch_lunch_order.php',
    ], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $fixtureDir, $environment);
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot start bootstrap test process');
    }
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($process), $output];
}

function assertSame(mixed $expected, mixed $actual, string $output): void
{
    if ($expected !== $actual) {
        throw new RuntimeException('Assertion failed: expected=' . var_export($expected, true)
            . ', actual=' . var_export($actual, true) . PHP_EOL . $output);
    }
}
