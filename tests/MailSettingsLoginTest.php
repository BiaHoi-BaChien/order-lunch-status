<?php

declare(strict_types=1);

// Exercise the real login handler with isolated configuration, sessions and limiter state.
$directory = sys_get_temp_dir() . '/mail-settings-login-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
mkdir($directory . '/src', 0700);
$files = ['mail_settings.php', 'src/EnvFileEditor.php', 'src/MailSettingsAuth.php', 'src/MailSettingsLoginLimiter.php'];
foreach ($files as $file) {
    copy(__DIR__ . '/../' . $file, $directory . '/' . $file);
}
file_put_contents($directory . '/.env', 'MAIL_SETTINGS_PASSWORD_HASH=' . password_hash('test-password', PASSWORD_BCRYPT, ['cost' => 4]) . "\n");
$statePath = $directory . '/order-lunch-status-mail-settings-login-rate-limit.json';

try {
    $wrong = login($directory, 'wrong-password');
    assertSame(429, $wrong['status']);
    assertSame(false, $wrong['authenticated']);
    assertSame(true, str_contains($wrong['html'], 'パスワードが正しくありません。'));

    // Persist a live block so this check is independent of second-boundary timing.
    $state = json_decode((string) file_get_contents($statePath), true, 512, JSON_THROW_ON_ERROR);
    $clientId = hash('sha256', '203.0.113.10');
    $state['clients'][$clientId]['blocked_until'] = time() + 60;
    file_put_contents($statePath, json_encode($state, JSON_THROW_ON_ERROR));
    $blocked = login($directory, 'test-password');
    assertSame(429, $blocked['status']);
    assertSame(false, $blocked['authenticated']);
    assertSame(true, str_contains($blocked['html'], 'ログイン試行回数が多すぎます。'));

    $state['clients'][$clientId]['blocked_until'] = time() - 1;
    file_put_contents($statePath, json_encode($state, JSON_THROW_ON_ERROR));
    $success = login($directory, 'test-password');
    assertSame(true, $success['authenticated']);
    assertSame(true, $success['regenerated']);
    $state = json_decode((string) file_get_contents($statePath), true, 512, JSON_THROW_ON_ERROR);
    assertSame(false, isset($state['clients'][$clientId]));
    assertSame(1, count($state['global']['failures']));

    // A state-file open failure must return 503 without granting a session.
    unlink($statePath);
    mkdir($statePath, 0700);
    $unavailable = login($directory, 'test-password', true);
    assertSame(503, $unavailable['status']);
    assertSame(false, $unavailable['authenticated']);
    assertSame(true, str_contains($unavailable['html'], 'ログインを一時的に利用できません。'));
    rmdir($statePath);

    echo "Mail settings login test passed\n";
} finally {
    foreach (glob($directory . '/src/*') ?: [] as $file) {
        unlink($file);
    }
    rmdir($directory . '/src');
    foreach (array_merge(glob($directory . '/*') ?: [], [$directory . '/.env']) as $file) {
        if (is_file($file)) {
            unlink($file);
        } elseif (is_dir($file)) {
            rmdir($file);
        }
    }
    rmdir($directory);
}

function login(string $directory, string $password, bool $expectStorageWarning = false): array
{
    $sessionId = 'testlogin' . bin2hex(random_bytes(6));
    $script = '<?php session_id(' . var_export($sessionId, true) . '); session_start();'
        . '$_SESSION = ["mail_settings_csrf" => "test-csrf"]; session_write_close();'
        . '$_SERVER["REQUEST_METHOD"] = "POST"; $_SERVER["REMOTE_ADDR"] = "203.0.113.10";'
        . '$_POST = ' . var_export(['action' => 'login', 'csrf' => 'test-csrf', 'password' => $password], true) . ';'
        . 'ob_start(); register_shutdown_function(static function () {'
        . '$html = ob_get_clean(); echo json_encode(["html" => $html, "status" => http_response_code(),'
        . '"authenticated" => $_SESSION["mail_settings_authenticated"] ?? false,'
        . '"regenerated" => session_id() !== ' . var_export($sessionId, true) . '], JSON_THROW_ON_ERROR); });'
        . 'require ' . var_export($directory . '/mail_settings.php', true) . ';';
    $process = proc_open(
        [PHP_BINARY, '-d', 'session.save_path=' . $directory, '-d', 'sys_temp_dir=' . $directory, '-d', 'display_errors=stderr'],
        [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
        $pipes,
        null,
        ['PATH' => (string) getenv('PATH'), 'SystemRoot' => (string) getenv('SystemRoot')]
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot start login test');
    }
    fwrite($pipes[0], $script);
    fclose($pipes[0]);
    $output = (string) stream_get_contents($pipes[1]);
    $errors = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    assertSame(0, proc_close($process));
    if (!$expectStorageWarning) {
        assertSame('', $errors);
    }
    return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
}

function assertSame(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException('Assertion failed: expected=' . var_export($expected, true) . ', actual=' . var_export($actual, true));
    }
}
