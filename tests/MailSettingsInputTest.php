<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/EnvFileEditor.php';

// Run the real settings page against a disposable .env and authenticated test session.
$directory = sys_get_temp_dir() . '/order-lunch-status-settings-' . bin2hex(random_bytes(6));
mkdir($directory);
mkdir($directory . '/src');
$files = ['mail_settings.php', 'src/EnvFileEditor.php', 'src/MailSettingsAuth.php', 'src/MailSettingsLoginLimiter.php'];
foreach ($files as $file) {
    copy(__DIR__ . '/../' . $file, $directory . '/' . $file);
}
$envPath = $directory . '/.env';
$initial = [
    'MAIL_SETTINGS_PASSWORD_HASH' => 'test-only-configured-hash',
    'UNRELATED_SETTING' => 'keep-me',
    'MAIL_MATSUYA_ORDER_FROM' => 'orders@example.com',
    'MAIL_MATSUYA_ORDER_SUBJECT' => '注文確認',
    'MAIL_MATSUYA_RECEIPT_FROM' => 'first@example.com｜second@example.com',
    'MAIL_MATSUYA_RECEIPT_SUBJECT' => '受付確認',
    'MAIL_RAMEN_KIMURA_ORDER_FROM' => 'kimura-orders@example.com',
    'MAIL_RAMEN_KIMURA_ORDER_SUBJECT' => '【お弁当注文確認】',
];

try {
    EnvFileEditor::updateValues($envPath, $initial);
    $html = renderPage($directory);
    assertContains('name="MAIL_MATSUYA_RECEIPT_FROM[]" value="first@example.com"', $html);
    assertContains('name="MAIL_MATSUYA_RECEIPT_FROM[]" value="second@example.com"', $html);
    assertContains('name="MAIL_RAMEN_KIMURA_ORDER_FROM[]" value="kimura-orders@example.com"', $html);
    assertContains('name="MAIL_RAMEN_KIMURA_ORDER_SUBJECT" value="ご注文を承りました"', $html);
    assertSame(false, str_contains($html, 'MAIL_RAMEN_KIMURA_RECEIPT_FROM'));
    assertSame(false, str_contains($html, 'MAIL_RAMEN_KIMURA_RECEIPT_SUBJECT'));
    assertSame(3, substr_count($html, 'class="add-email"'));
    assertSame(false, str_contains($html, '区切りで複数指定可'));

    $post = ['action' => 'save', 'csrf' => 'test-csrf'];
    foreach ($initial as $key => $value) {
        if (str_ends_with($key, '_FROM')) {
            $post[$key] = [' first@example.com ', '', ' ', 'third@example.com'];
        } elseif (str_ends_with($key, '_SUBJECT')) {
            $post[$key] = '新しい件名';
        }
    }
    $html = renderPage($directory, $post);
    assertContains('.env を更新しました。', $html);
    $saved = EnvFileEditor::readValues($envPath);
    foreach ($post as $key => $value) {
        if (str_ends_with($key, '_FROM')) {
            assertSame('first@example.com|third@example.com', $saved[$key]);
        }
    }
    assertSame('keep-me', $saved['UNRELATED_SETTING']);
    assertSame($initial['MAIL_SETTINGS_PASSWORD_HASH'], $saved['MAIL_SETTINGS_PASSWORD_HASH']);
    assertContains('name="MAIL_MATSUYA_RECEIPT_FROM[]" value="third@example.com"', renderPage($directory));
    $savedContent = file_get_contents($envPath);

    foreach (['not-an-email', 'one@example.com|two@example.com', 'one@example.com｜two@example.com', "first@example.com\nINJECTED=value", ['nested@example.com']] as $invalid) {
        $invalidPost = $post;
        $invalidPost['MAIL_MATSUYA_RECEIPT_FROM'] = ['valid@example.com', $invalid];
        $invalidPost['MAIL_RAMEN_KIMURA_ORDER_SUBJECT'] = '保存前の入力を保持';
        $html = renderPage($directory, $invalidPost);
        assertContains('class="alert error"', $html);
        assertContains('松屋の受付確認メールの送信元（2件目）', $html);
        assertContains('value="保存前の入力を保持"', $html);
        assertSame($savedContent, file_get_contents($envPath));
    }

    $invalidPost = $post;
    $invalidPost['MAIL_MATSUYA_RECEIPT_FROM'] = ['valid@example.com', '"><script>alert(1)</script>'];
    $html = renderPage($directory, $invalidPost);
    assertContains('value="valid@example.com"', $html);
    assertContains('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    assertSame(false, str_contains($html, '<script>alert(1)</script>'));
    assertSame($savedContent, file_get_contents($envPath));

    foreach (['MAIL_MATSUYA_RECEIPT_FROM' => 'scalar@example.com', 'MAIL_MATSUYA_ORDER_SUBJECT' => ['unexpected-array']] as $key => $value) {
        $invalidPost = $post;
        $invalidPost[$key] = $value;
        assertContains('class="alert error"', renderPage($directory, $invalidPost));
        assertSame($savedContent, file_get_contents($envPath));
    }

    $invalidPost = $post;
    $invalidPost['csrf'] = 'wrong-token';
    assertContains('セッションが期限切れです。', renderPage($directory, $invalidPost));
    assertSame($savedContent, file_get_contents($envPath));

    $post['MAIL_MATSUYA_RECEIPT_FROM'] = [''];
    $post['MAIL_RAMEN_KIMURA_ORDER_FROM'] = ['only@example.com'];
    assertContains('.env を更新しました。', renderPage($directory, $post));
    $saved = EnvFileEditor::readValues($envPath);
    assertSame('', $saved['MAIL_MATSUYA_RECEIPT_FROM']);
    assertSame('only@example.com', $saved['MAIL_RAMEN_KIMURA_ORDER_FROM']);
    assertContains('name="MAIL_MATSUYA_RECEIPT_FROM[]" value=""', renderPage($directory));
} finally {
    foreach (array_merge($files, ['.env', 'sess_testsettings']) as $file) {
        if (is_file($directory . '/' . $file)) {
            unlink($directory . '/' . $file);
        }
    }
    rmdir($directory . '/src');
    rmdir($directory);
}

echo "Mail settings input test passed\n";

function renderPage(string $directory, array $post = []): string
{
    $script = '<?php session_id("testsettings"); session_start();'
        . '$_SESSION = ["mail_settings_authenticated" => true, "mail_settings_csrf" => "test-csrf"]; session_write_close();'
        . '$_SERVER["REQUEST_METHOD"] = ' . var_export($post === [] ? 'GET' : 'POST', true) . ';'
        . '$_POST = ' . var_export($post, true) . ';'
        . 'require ' . var_export($directory . '/mail_settings.php', true) . ';';
    $process = proc_open(
        [PHP_BINARY, '-d', 'session.save_path=' . $directory, '-d', 'display_errors=stderr'],
        [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
        $pipes
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot start settings page test');
    }
    fwrite($pipes[0], $script);
    fclose($pipes[0]);
    $html = (string) stream_get_contents($pipes[1]);
    $errors = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    assertSame(0, proc_close($process));
    assertSame('', $errors);
    return $html;
}

function assertSame(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException('Assertion failed: expected=' . var_export($expected, true) . ', actual=' . var_export($actual, true));
    }
}

function assertContains(string $expected, string $actual): void
{
    if (!str_contains($actual, $expected)) {
        throw new RuntimeException("Assertion failed: {$expected} not found");
    }
}
