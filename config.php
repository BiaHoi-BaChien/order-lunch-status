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

function envString(string $key, string $default): string
{
    $value = getenv($key);
    if ($value === false) {
        return $default;
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

function envPositiveInt(string $key, int $default, int $max): int
{
    $value = getenv($key);
    if ($value === false || $value === '') {
        return $default;
    }

    if (preg_match('/^[1-9]\d*$/', $value) !== 1) {
        throw new RuntimeException("環境変数 {$key} は 1 以上 {$max} 以下の整数を指定してください");
    }

    $intValue = (int) $value;
    if ($intValue > $max) {
        throw new RuntimeException("環境変数 {$key} は 1 以上 {$max} 以下の整数を指定してください");
    }

    return $intValue;
}

/**
 * @return list<string>
 */
function envList(string $key, array $default): array
{
    $value = getenv($key);
    if ($value === false || $value === '') {
        return array_values($default);
    }

    $items = array_values(array_filter(
        array_map('trim', explode('|', $value)),
        static fn (string $item): bool => $item !== ''
    ));

    return $items === [] ? array_values($default) : $items;
}

function envOptionalPath(string $key): ?string
{
    $value = getenv($key);
    if ($value === false || $value === '') {
        return null;
    }

    $path = projectPath($value);
    if (!is_file($path)) {
        throw new RuntimeException("環境変数 {$key} のファイルが見つかりません: {$path}");
    }

    return $path;
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
$runWindowEnabled = envBool('RUN_WINDOW_ENABLED', true);
$runWindowStartHour = (int) envValue('RUN_WINDOW_START_HOUR', '9');
$runWindowEndHour = (int) envValue('RUN_WINDOW_END_HOUR', '23');
$mailOrderFrom = envString('MAIL_ORDER_FROM', 'forms-receipts-noreply@google.com');
$mailOrderSubject = envValue('MAIL_ORDER_SUBJECT', 'フォームにご記入いただきありがとうございます');
$mailReceiptSubject = envValue('MAIL_RECEIPT_SUBJECT', '【松屋】お弁当注文受付確認');
if ($slackNotificationEnabled && $slackWebhookUrl === '') {
    throw new RuntimeException('SLACK_NOTIFICATION_ENABLED=true の場合は SLACK_WEBHOOK_URL を設定してください');
}

return [
    'notion_api_key' => envValue('NOTION_API_KEY', ''),
    'notion_order_data_source_id' => envValue('NOTION_ORDER_DATA_SOURCE_ID', ''),
    'notion_ticket_data_source_id' => envValue('NOTION_TICKET_DATA_SOURCE_ID', ''),
    'gmail_user_id' => envValue('GMAIL_USER_ID', 'me'),
    'timezone' => $timezone,
    'lookback_days' => envPositiveInt('LOOKBACK_DAYS', 7, 100),
    'initial_record_days' => envPositiveInt('INITIAL_RECORD_DAYS', 30, 100),
    'shop_name' => envValue('SHOP_NAME', '松屋'),
    'gmail_credentials_path' => projectPath(envValue('GMAIL_CREDENTIALS_PATH', 'credentials/gmail_credentials.json')),
    'gmail_token_path' => projectPath(envValue('GMAIL_TOKEN_PATH', 'credentials/gmail_token.json')),
    'curl_ca_bundle_path' => envOptionalPath('CURL_CA_BUNDLE'),
    'log_output_unit' => $logOutputUnit,
    'log_file_path' => logFilePath($logOutputUnit, $timezone),
    'slack_notification_enabled' => $slackNotificationEnabled,
    'slack_webhook_url' => $slackWebhookUrl,
    'run_window_enabled' => $runWindowEnabled,
    'run_window_start_hour' => $runWindowStartHour,
    'run_window_end_hour' => $runWindowEndHour,
    'mail_order_from' => $mailOrderFrom,
    'mail_order_subject' => $mailOrderSubject,
    'mail_receipt_subject' => $mailReceiptSubject,
    'mail_parser' => [
        'date_labels' => envList('MAIL_FIELD_DATE_LABELS', [
            'お子様がお弁当を召し上がる日付を記載してください',
            'お弁当を召し上がる日付',
        ]),
        'ticket_labels' => envList('MAIL_FIELD_TICKET_LABELS', [
            'お手持ちのお弁当券に記載してある数字4ケタのお弁当ナンバー',
            'お弁当ナンバー',
            'お弁当番号',
        ]),
        'item_labels' => envList('MAIL_FIELD_ITEM_LABELS', [
            '品名',
            '注文したお弁当',
            'お弁当の種類',
            'メニュー',
            'アレルギー物質',
        ]),
        'size_labels' => envList('MAIL_FIELD_SIZE_LABELS', [
            'ライスの量',
            'ご飯の量',
            'サイズ',
        ]),
        'note_labels' => envList('MAIL_FIELD_NOTE_LABELS', [
            '備考',
            'ご要望',
        ]),
        'curry_type_labels' => envList('MAIL_FIELD_CURRY_TYPE_LABELS', [
            'カレーの種類',
        ]),
        'known_items' => envList('MAIL_KNOWN_ITEMS', [
            '牛めし（A券：牛めし）',
            'キムチ牛めし（B券：定食・丼）',
            '唐揚げ定食（B券：定食・丼）',
            'ふわ玉あんかけ牛めし（B券：定食・丼）',
            'ふわとろあんかけ牛めし（B券：定食・丼）',
            'チキンかつカレー（B券：定食・丼）',
        ]),
    ],
];
