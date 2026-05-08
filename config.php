<?php

declare(strict_types=1);

function loadEnvFile(string $path): void
{
    if (!is_file($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        $value = trim($value, "\"'");

        if ($key !== '' && getenv($key) === false) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
        }
    }
}

function envValue(string $key, ?string $default = null): string
{
    $value = getenv($key);
    if ($value === false || $value === '') {
        if ($default !== null) {
            return $default;
        }

        throw new RuntimeException("環境変数 {$key} が未設定です");
    }

    return $value;
}

function envBool(string $key, bool $default = false): bool
{
    $value = getenv($key);
    if ($value === false || $value === '') {
        return $default;
    }

    return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
}

function isAbsolutePath(string $path): bool
{
    return str_starts_with($path, '/')
        || str_starts_with($path, '\\')
        || preg_match('/^[A-Za-z]:[\/\\\\]/', $path) === 1;
}

function projectPath(string $path): string
{
    if (isAbsolutePath($path)) {
        return $path;
    }

    return __DIR__ . '/' . ltrim($path, '/\\');
}

function logFilePath(string $unit, string $timezone): string
{
    $now = new DateTimeImmutable('now', new DateTimeZone($timezone));

    return match ($unit) {
        'daily' => __DIR__ . '/logs/lunch_batch_' . $now->format('Ymd') . '.log',
        'monthly' => __DIR__ . '/logs/lunch_batch_' . $now->format('Ym') . '.log',
        'single' => __DIR__ . '/logs/lunch_batch.log',
        default => throw new RuntimeException('LOG_OUTPUT_UNIT は daily, monthly, single のいずれかを指定してください'),
    };
}

loadEnvFile(__DIR__ . '/.env');

$timezone = envValue('TIMEZONE', 'Asia/Ho_Chi_Minh');
$logOutputUnit = strtolower(envValue('LOG_OUTPUT_UNIT', 'daily'));
$slackNotificationEnabled = envBool('SLACK_NOTIFICATION_ENABLED', false);
$slackWebhookUrl = envValue('SLACK_WEBHOOK_URL', '');
if ($slackNotificationEnabled && $slackWebhookUrl === '') {
    throw new RuntimeException('SLACK_NOTIFICATION_ENABLED=true の場合は SLACK_WEBHOOK_URL を設定してください');
}

return [
    'notion_api_key' => envValue('NOTION_API_KEY'),
    'notion_order_data_source_id' => envValue('NOTION_ORDER_DATA_SOURCE_ID'),
    'notion_ticket_data_source_id' => envValue('NOTION_TICKET_DATA_SOURCE_ID'),
    'gmail_user_id' => envValue('GMAIL_USER_ID', 'me'),
    'timezone' => $timezone,
    'lookback_days' => (int) envValue('LOOKBACK_DAYS', '7'),
    'initial_record_days' => (int) envValue('INITIAL_RECORD_DAYS', '30'),
    'shop_name' => envValue('SHOP_NAME', '松屋'),
    'gmail_credentials_path' => projectPath(envValue('GMAIL_CREDENTIALS_PATH', 'credentials/gmail_credentials.json')),
    'gmail_token_path' => projectPath(envValue('GMAIL_TOKEN_PATH', 'credentials/gmail_token.json')),
    'log_output_unit' => $logOutputUnit,
    'log_file_path' => logFilePath($logOutputUnit, $timezone),
    'slack_notification_enabled' => $slackNotificationEnabled,
    'slack_webhook_url' => $slackWebhookUrl,
];
