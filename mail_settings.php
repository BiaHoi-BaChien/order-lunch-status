<?php

declare(strict_types=1);

require_once __DIR__ . '/src/EnvFileEditor.php';
require_once __DIR__ . '/src/MailSettingsAuth.php';
require_once __DIR__ . '/src/MailSettingsLoginLimiter.php';
require_once __DIR__ . '/src/MailParser.php';

session_start();
header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet, noimageindex');

$envPath = __DIR__ . '/.env';
$settingGroups = [
    '松屋' => [
        'MAIL_MATSUYA_ORDER_FROM' => ['label' => '注文確認メールの送信元', 'type' => 'text'],
        'MAIL_MATSUYA_ORDER_SUBJECT' => ['label' => '注文確認メールの件名', 'type' => 'text'],
        'MAIL_MATSUYA_RECEIPT_FROM' => ['label' => '受付確認メールの送信元（|区切りで複数指定可）', 'type' => 'text'],
        'MAIL_MATSUYA_RECEIPT_SUBJECT' => ['label' => '受付確認メールの件名', 'type' => 'text'],
        'MAIL_MATSUYA_FIELD_DATE_LABELS' => ['label' => '日付欄の質問文', 'type' => 'list'],
        'MAIL_MATSUYA_FIELD_TICKET_LABELS' => ['label' => 'お弁当番号欄の質問文', 'type' => 'list'],
        'MAIL_MATSUYA_FIELD_ITEM_LABELS' => ['label' => '品名欄の質問文', 'type' => 'list'],
        'MAIL_MATSUYA_FIELD_SIZE_LABELS' => ['label' => 'サイズ欄の質問文', 'type' => 'list'],
        'MAIL_MATSUYA_FIELD_NOTE_LABELS' => ['label' => '備考欄の質問文', 'type' => 'list'],
        'MAIL_MATSUYA_FIELD_NOTE_APPEND_LABELS' => ['label' => '備考へ追記する質問文', 'type' => 'list'],
        'MAIL_MATSUYA_KNOWN_ITEMS' => ['label' => '認識する品名候補', 'type' => 'list'],
    ],
    'RAMEN KIMURA' => [
        'MAIL_RAMEN_KIMURA_ORDER_FROM' => ['label' => '注文確認メールの送信元', 'type' => 'text'],
        'MAIL_RAMEN_KIMURA_ORDER_SUBJECT' => ['label' => '注文確認メールの件名', 'type' => 'text'],
    ],
];

$defaults = [
    'MAIL_MATSUYA_ORDER_FROM' => 'forms-receipts-noreply@google.com',
    'MAIL_MATSUYA_ORDER_SUBJECT' => 'フォームにご記入いただきありがとうございます',
    'MAIL_MATSUYA_RECEIPT_FROM' => '',
    'MAIL_MATSUYA_RECEIPT_SUBJECT' => '【松屋】お弁当注文受付確認',
    'MAIL_RAMEN_KIMURA_ORDER_FROM' => 'tobe.kimura@gmail.com',
    'MAIL_RAMEN_KIMURA_ORDER_SUBJECT' => '【お弁当注文確認】',
    'MAIL_MATSUYA_FIELD_DATE_LABELS' => implode('|', MailParser::DEFAULT_DATE_LABELS),
    'MAIL_MATSUYA_FIELD_TICKET_LABELS' => implode('|', MailParser::DEFAULT_TICKET_LABELS),
    'MAIL_MATSUYA_FIELD_ITEM_LABELS' => implode('|', MailParser::DEFAULT_ITEM_LABELS),
    'MAIL_MATSUYA_FIELD_SIZE_LABELS' => implode('|', MailParser::DEFAULT_SIZE_LABELS),
    'MAIL_MATSUYA_FIELD_NOTE_LABELS' => implode('|', MailParser::DEFAULT_NOTE_LABELS),
    'MAIL_MATSUYA_FIELD_NOTE_APPEND_LABELS' => implode('|', MailParser::DEFAULT_NOTE_APPEND_LABELS),
    'MAIL_MATSUYA_KNOWN_ITEMS' => implode('|', MailParser::DEFAULT_KNOWN_ITEMS),
];

$message = null;
$error = null;
$envFileValues = EnvFileEditor::readValues($envPath);
$auth = MailSettingsAuth::fromEnvironment($envFileValues);
$passwordConfigured = $auth->isConfigured();

if (!$passwordConfigured) {
    http_response_code(503);
    echo 'MAIL_SETTINGS_PASSWORD_HASH を設定するまで、この画面は利用できません。';
    exit;
}

if (empty($_SESSION['mail_settings_csrf'])) {
    $_SESSION['mail_settings_csrf'] = bin2hex(random_bytes(32));
}

if ($passwordConfigured && !($_SESSION['mail_settings_authenticated'] ?? false)) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
        verifyCsrf((string) ($_POST['csrf'] ?? ''), (string) $_SESSION['mail_settings_csrf']);
        try {
            $limiter = new MailSettingsLoginLimiter(
                sys_get_temp_dir() . '/order-lunch-status-mail-settings-login-rate-limit.json'
            );
            $clientKey = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
            $retryAfter = $limiter->retryAfter($clientKey);

            if ($retryAfter === 0 && $auth->verify((string) ($_POST['password'] ?? ''))) {
                $limiter->recordSuccess($clientKey);
                if (!session_regenerate_id(true)) {
                    throw new RuntimeException('ログインセッションを更新できません');
                }
                $_SESSION['mail_settings_authenticated'] = true;
                header('Location: ' . requestPath());
                exit;
            }

            if ($retryAfter === 0) {
                $retryAfter = $limiter->recordFailure($clientKey);
                $error = 'パスワードが正しくありません。';
            } else {
                $error = 'ログイン試行回数が多すぎます。';
            }

            http_response_code(429);
            header('Retry-After: ' . $retryAfter);
            $error .= " {$retryAfter} 秒後に再試行してください。";
        } catch (Throwable) {
            http_response_code(503);
            $error = 'ログインを一時的に利用できません。時間をおいて再試行してください。';
        }
    }

    renderLogin((string) $_SESSION['mail_settings_csrf'], $error);
    exit;
}

try {
    $values = array_replace($defaults, $envFileValues);

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
        verifyCsrf((string) ($_POST['csrf'] ?? ''), (string) $_SESSION['mail_settings_csrf']);

        $updates = [];
        foreach ($settingGroups as $settings) {
            foreach ($settings as $key => $meta) {
                if ($meta['type'] === 'list') {
                    $postedItems = $_POST[$key] ?? [];
                    if (!is_array($postedItems)) {
                        $postedItems = [];
                    }
                    $updates[$key] = EnvFileEditor::listToEnv(array_map('strval', $postedItems));
                    continue;
                }

                $updates[$key] = trim((string) ($_POST[$key] ?? ''));
            }
        }

        EnvFileEditor::updateValues($envPath, $updates);
        $values = array_replace($values, $updates);
        $message = '.env を更新しました。次回のバッチ実行から反映されます。';
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
    $values = array_replace($defaults, EnvFileEditor::readValues($envPath));
}

renderSettings($settingGroups, $values, (string) $_SESSION['mail_settings_csrf'], $message, $error);

function requestPath(): string
{
    return 'mail_settings.php';
}

function verifyCsrf(string $posted, string $expected): void
{
    if ($posted === '' || !hash_equals($expected, $posted)) {
        throw new RuntimeException('セッションが期限切れです。再読み込みしてから保存してください。');
    }
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function renderLogin(string $csrf, ?string $error): void
{
    renderHeader('メール解析設定ログイン');
    if ($error !== null) {
        echo '<p class="alert error">' . h($error) . '</p>';
    }
    echo '<form method="post" class="panel">';
    echo '<input type="hidden" name="action" value="login">';
    echo '<input type="hidden" name="csrf" value="' . h($csrf) . '">';
    echo '<label>パスワード<input type="password" name="password" autocomplete="current-password" autofocus></label>';
    echo '<button type="submit">ログイン</button>';
    echo '</form>';
    renderFooter();
}

/**
 * @param array<string, array<string, array{label:string,type:string}>> $settingGroups
 * @param array<string, string> $values
 */
function renderSettings(array $settingGroups, array $values, string $csrf, ?string $message, ?string $error): void
{
    renderHeader('メール解析設定');
    if ($message !== null) {
        echo '<p class="alert success">' . h($message) . '</p>';
    }
    if ($error !== null) {
        echo '<p class="alert error">' . h($error) . '</p>';
    }

    echo '<form method="post">';
    echo '<input type="hidden" name="action" value="save">';
    echo '<input type="hidden" name="csrf" value="' . h($csrf) . '">';

    foreach ($settingGroups as $group => $settings) {
        echo '<section class="settings-group">';
        echo '<h2>' . h($group) . '</h2>';
        foreach ($settings as $key => $meta) {
            $value = $values[$key] ?? '';
            echo '<div class="setting">';
            echo '<div><h3>' . h($meta['label']) . '</h3><code>' . h($key) . '</code></div>';

            if ($meta['type'] === 'list') {
                echo '<div class="list">';
                $items = EnvFileEditor::envToList($value);
                $items[] = '';
                foreach ($items as $item) {
                    echo '<input type="text" name="' . h($key) . '[]" value="' . h($item) . '" placeholder="追加する値">';
                }
                echo '</div>';
                echo '<p class="hint">空欄は保存時に無視されます。複数項目は .env では | 区切りで保存します。</p>';
            } else {
                echo '<input type="text" name="' . h($key) . '" value="' . h($value) . '">';
            }

            echo '</div>';
        }
        echo '</section>';
    }

    echo '<div class="actions"><button type="submit">保存</button></div>';
    echo '</form>';
    renderFooter();
}

function renderHeader(string $title): void
{
    echo '<!doctype html><html lang="ja"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<meta name="robots" content="noindex,nofollow,noarchive,nosnippet,noimageindex">';
    echo '<title>' . h($title) . '</title>';
    echo '<style>
        body{margin:0;background:#f6f7f9;color:#1f2933;font-family:system-ui,-apple-system,"Segoe UI",sans-serif;line-height:1.5}
        main{max-width:920px;margin:0 auto;padding:32px 20px 48px}
        h1{font-size:28px;margin:0 0 20px}
        h2{font-size:20px;margin:0;padding-bottom:10px;border-bottom:2px solid #9fb3c8}
        h3{font-size:16px;margin:0 0 4px}
        code{color:#52606d;font-size:13px}
        .panel{background:#fff;border:1px solid #d9e2ec;border-radius:8px;padding:18px;margin:14px 0}
        .settings-group{margin:28px 0}
        .setting{padding:18px 0;border-bottom:1px solid #d9e2ec}
        label{display:grid;gap:8px;font-weight:600}
        input{box-sizing:border-box;width:100%;border:1px solid #bcccdc;border-radius:6px;padding:10px 12px;font:inherit;background:#fff}
        .list{display:grid;gap:8px;margin-top:14px}
        .hint{margin:10px 0 0;color:#627d98;font-size:13px}
        .actions{position:sticky;bottom:0;background:rgba(246,247,249,.94);padding:16px 0;border-top:1px solid #d9e2ec}
        button{background:#0f609b;color:#fff;border:0;border-radius:6px;padding:10px 18px;font-weight:700;cursor:pointer}
        .alert{border-radius:6px;padding:12px 14px}
        .success{background:#e3f9e5;color:#276749}
        .error{background:#ffe3e3;color:#9b1c1c}
    </style></head><body><main><h1>' . h($title) . '</h1>';
}

function renderFooter(): void
{
    echo '</main></body></html>';
}
