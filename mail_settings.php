<?php

declare(strict_types=1);

require_once __DIR__ . '/src/EnvFileEditor.php';
require_once __DIR__ . '/src/MailSettingsAuth.php';
require_once __DIR__ . '/src/MailSettingsLoginLimiter.php';

session_start();
header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet, noimageindex');

$envPath = __DIR__ . '/.env';
$settingGroups = [
    '松屋' => [
        'MAIL_MATSUYA_ORDER_FROM' => '注文確認メールの送信元',
        'MAIL_MATSUYA_ORDER_SUBJECT' => '注文確認メールの件名',
        'MAIL_MATSUYA_RECEIPT_FROM' => '受付確認メールの送信元',
        'MAIL_MATSUYA_RECEIPT_SUBJECT' => '受付確認メールの件名',
    ],
    'RAMEN KIMURA' => [
        'MAIL_RAMEN_KIMURA_ORDER_FROM' => '「ご注文を承りました」メールの送信元',
        'MAIL_RAMEN_KIMURA_ORDER_SUBJECT' => '「ご注文を承りました」メールの件名',
    ],
];

$defaults = [
    'MAIL_MATSUYA_ORDER_FROM' => 'forms-receipts-noreply@google.com',
    'MAIL_MATSUYA_ORDER_SUBJECT' => 'フォームにご記入いただきありがとうございます',
    'MAIL_MATSUYA_RECEIPT_FROM' => '',
    'MAIL_MATSUYA_RECEIPT_SUBJECT' => '【松屋】お弁当注文受付確認',
    'MAIL_RAMEN_KIMURA_ORDER_FROM' => 'tobe.kimura@gmail.com',
    'MAIL_RAMEN_KIMURA_ORDER_SUBJECT' => 'ご注文を承りました',
];

$message = null;
$error = null;
$envFileValues = EnvFileEditor::readValues($envPath);
$kimuraSubject = trim($envFileValues['MAIL_RAMEN_KIMURA_ORDER_SUBJECT'] ?? '');
$envFileValues['MAIL_RAMEN_KIMURA_ORDER_SUBJECT'] = in_array($kimuraSubject, ['', '【お弁当注文確認】'], true)
    ? $defaults['MAIL_RAMEN_KIMURA_ORDER_SUBJECT']
    : $kimuraSubject;
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

$values = array_replace($defaults, $envFileValues);

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
        verifyCsrf((string) ($_POST['csrf'] ?? ''), (string) $_SESSION['mail_settings_csrf']);

        // Keep all submitted fields visible if validation or saving fails.
        foreach ($settingGroups as $settings) {
            foreach ($settings as $key => $_label) {
                $input = $_POST[$key] ?? (str_ends_with($key, '_FROM') ? [] : '');
                if (is_string($input) || (is_array($input) && count(array_filter($input, 'is_string')) === count($input))) {
                    $values[$key] = $input;
                }
            }
        }

        $updates = [];
        foreach ($settingGroups as $group => $settings) {
            foreach ($settings as $key => $label) {
                $input = $_POST[$key] ?? (str_ends_with($key, '_FROM') ? [] : '');
                if (str_ends_with($key, '_FROM')) {
                    if (!is_array($input)) {
                        throw new InvalidArgumentException("{$group}の{$label}を確認してください。");
                    }
                    foreach (array_values($input) as $index => $address) {
                        if (!is_string($address) || preg_match('/[|｜\r\n]/u', $address) === 1
                            || (trim($address) !== '' && filter_var(trim($address), FILTER_VALIDATE_EMAIL) === false)) {
                            $number = $index + 1;
                            throw new InvalidArgumentException("{$group}の{$label}（{$number}件目）には、メールアドレスを1件入力してください。");
                        }
                    }
                    $updates[$key] = EnvFileEditor::listToEnv($input);
                } else {
                    if (!is_string($input)) {
                        throw new InvalidArgumentException("{$group}の{$label}を確認してください。");
                    }
                    $updates[$key] = trim($input);
                }
            }
        }

        EnvFileEditor::updateValues($envPath, $updates);
        $values = array_replace($values, $updates);
        $message = '.env を更新しました。次回のバッチ実行から反映されます。';
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
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
 * @param array<string, array<string, string>> $settingGroups
 * @param array<string, string|array<string>> $values
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
        if ($group === 'RAMEN KIMURA') {
            echo '<p>このメール1通で注文内容を登録し、受付済に更新します。</p>';
        }
        foreach ($settings as $key => $label) {
            $value = $values[$key] ?? '';
            echo '<div class="setting">';
            echo '<div><h3>' . h($label) . '</h3><code>' . h($key) . '</code></div>';
            if (str_ends_with($key, '_FROM')) {
                $addresses = is_array($value) ? array_values($value) : EnvFileEditor::envToList($value);
                echo '<div class="email-list" data-label="' . h($group . ' ' . $label) . '">';
                echo '<div class="email-rows">';
                foreach ($addresses ?: [''] as $index => $address) {
                    $number = $index + 1;
                    echo '<div class="email-row">';
                    echo '<input type="email" name="' . h($key) . '[]" value="' . h($address) . '" aria-label="' . h($group . ' ' . $label . ' ' . $number) . '" placeholder="name@example.com">';
                    echo '<button type="button" class="remove-email" aria-label="メールアドレス' . $number . 'を削除">削除</button>';
                    echo '</div>';
                }
                echo '</div><button type="button" class="add-email">メールアドレスを追加</button></div>';
            } else {
                echo '<input type="text" name="' . h($key) . '" value="' . h(is_string($value) ? $value : '') . '" aria-label="' . h($group . ' ' . $label) . '">';
            }
            echo '</div>';
        }
        echo '</section>';
    }

    echo '<div class="actions"><button type="submit">保存</button></div>';
    echo '</form>';
    echo <<<'HTML'
    <script>
        document.querySelectorAll('.email-list').forEach((list) => {
            const rows = list.querySelector('.email-rows');
            const updateLabels = () => {
                rows.querySelectorAll('.email-row').forEach((row, index) => {
                    row.querySelector('input').setAttribute('aria-label', `${list.dataset.label} ${index + 1}`);
                    row.querySelector('button').setAttribute('aria-label', `メールアドレス${index + 1}を削除`);
                });
            };
            list.querySelector('.add-email').addEventListener('click', () => {
                const row = rows.firstElementChild.cloneNode(true);
                const input = row.querySelector('input');
                input.value = '';
                rows.append(row);
                updateLabels();
                input.focus();
            });
            rows.addEventListener('click', (event) => {
                const button = event.target.closest('.remove-email');
                if (!button) return;
                const row = button.closest('.email-row');
                if (rows.children.length === 1) {
                    row.querySelector('input').value = '';
                    row.querySelector('input').focus();
                } else {
                    const nextRow = row.nextElementSibling || row.previousElementSibling;
                    row.remove();
                    updateLabels();
                    nextRow.querySelector('input').focus();
                }
            });
        });
    </script>
    HTML;
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
        code{color:#52606d;font-size:13px;overflow-wrap:anywhere}
        .panel{background:#fff;border:1px solid #d9e2ec;border-radius:8px;padding:18px;margin:14px 0}
        .settings-group{margin:28px 0}
        .setting{padding:18px 0;border-bottom:1px solid #d9e2ec}
        label{display:grid;gap:8px;font-weight:600}
        input{box-sizing:border-box;width:100%;border:1px solid #bcccdc;border-radius:6px;padding:10px 12px;font:inherit;background:#fff}
        .actions{position:sticky;bottom:0;background:rgba(246,247,249,.94);padding:16px 0;border-top:1px solid #d9e2ec}
        button{background:#0f609b;color:#fff;border:0;border-radius:6px;padding:10px 18px;font-weight:700;cursor:pointer}
        .email-list,.email-rows{display:grid;gap:8px}
        .email-row{display:flex;gap:8px;align-items:center}
        .email-row input{flex:1;min-width:0}
        .remove-email{flex:none;background:#fff;color:#9b1c1c;border:1px solid #d9e2ec}
        .add-email{justify-self:start;background:#fff;color:#0f609b;border:1px solid #bcccdc}
        input:focus-visible,button:focus-visible{outline:2px solid #0f609b;outline-offset:2px}
        .alert{border-radius:6px;padding:12px 14px}
        .success{background:#e3f9e5;color:#276749}
        .error{background:#ffe3e3;color:#9b1c1c}
    </style></head><body><main><h1>' . h($title) . '</h1>';
}

function renderFooter(): void
{
    echo '</main></body></html>';
}
