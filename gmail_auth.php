<?php

declare(strict_types=1);

const GMAIL_MODIFY_SCOPE = 'https://www.googleapis.com/auth/gmail.modify';

try {
    $config = require __DIR__ . '/config.php';
    date_default_timezone_set($config['timezone']);

    $credentials = readJsonFile($config['gmail_credentials_path'], 'Gmail OAuth credentials');
    $client = oauthClient($credentials);

    foreach (['client_id', 'client_secret'] as $key) {
        if (empty($client[$key])) {
            throw new RuntimeException("Gmail OAuth credentialsに {$key} がありません");
        }
    }

    [$server, $redirectUri] = startLoopbackServer();
    $state = base64Url(random_bytes(32));
    $codeVerifier = base64Url(random_bytes(64));
    $codeChallenge = base64Url(hash('sha256', $codeVerifier, true));

    $authUrl = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
        'client_id' => $client['client_id'],
        'redirect_uri' => $redirectUri,
        'response_type' => 'code',
        'scope' => GMAIL_MODIFY_SCOPE,
        'access_type' => 'offline',
        'prompt' => 'consent',
        'code_challenge' => $codeChallenge,
        'code_challenge_method' => 'S256',
        'state' => $state,
    ], '', '&', PHP_QUERY_RFC3986);

    echo "以下のURLをブラウザで開き、Gmailアクセスを許可してください。\n\n";
    echo $authUrl . "\n\n";
    echo "認可後、この画面に戻るまで待ってください。\n";

    $query = waitForOAuthCallback($server);
    fclose($server);

    if (($query['state'] ?? '') !== $state) {
        throw new RuntimeException('OAuth stateが一致しません');
    }
    if (!empty($query['error'])) {
        throw new RuntimeException('OAuth認可エラー: ' . $query['error']);
    }
    if (empty($query['code'])) {
        throw new RuntimeException('OAuth認可コードを取得できませんでした');
    }

    $token = exchangeCodeForToken($client, (string) $query['code'], $redirectUri, $codeVerifier, $config['curl_ca_bundle_path']);
    if (empty($token['refresh_token'])) {
        throw new RuntimeException('refresh_tokenを取得できませんでした。Googleの権限画面でアプリ連携を解除してから再実行してください。');
    }

    $token['expires_at'] = time() + (int) ($token['expires_in'] ?? 3600);
    writeJsonFile($config['gmail_token_path'], $token);

    echo "\nGmail OAuthトークンを保存しました: {$config['gmail_token_path']}\n";
    echo "次に php batch_lunch_order.php を実行できます。\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

function readJsonFile(string $path, string $label): array
{
    if (!is_file($path)) {
        throw new RuntimeException("{$label}ファイルがありません: {$path}");
    }

    if (filesize($path) === 0) {
        throw new RuntimeException("{$label}ファイルが空です: {$path}");
    }

    $decoded = json_decode((string) file_get_contents($path), true);
    if (!is_array($decoded)) {
        throw new RuntimeException("{$label} JSONを解析できません: {$path}");
    }

    return $decoded;
}

function oauthClient(array $credentials): array
{
    return $credentials['installed'] ?? $credentials['web'] ?? $credentials;
}

function startLoopbackServer(): array
{
    $server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($server === false) {
        throw new RuntimeException("OAuth callback用ローカルサーバーを開始できません: {$errstr} ({$errno})");
    }

    $name = stream_socket_get_name($server, false);
    if (!is_string($name) || !str_contains($name, ':')) {
        fclose($server);
        throw new RuntimeException('OAuth callback用ローカルサーバーのポート番号を取得できません');
    }

    $port = substr(strrchr($name, ':'), 1);
    stream_set_timeout($server, 300);

    return [$server, "http://127.0.0.1:{$port}/callback"];
}

function waitForOAuthCallback($server): array
{
    $connection = @stream_socket_accept($server, 300);
    if ($connection === false) {
        throw new RuntimeException('OAuth callbackの待機がタイムアウトしました');
    }

    $requestLine = fgets($connection);
    $query = [];
    $statusLine = 'HTTP/1.1 200 OK';
    $body = '<html><body><h1>認可が完了しました</h1><p>このブラウザを閉じて、ターミナルに戻ってください。</p></body></html>';

    if (!is_string($requestLine) || !preg_match('#^GET\s+([^\s]+)\s+HTTP/#', $requestLine, $matches)) {
        $statusLine = 'HTTP/1.1 400 Bad Request';
        $body = '<html><body><h1>認可リクエストを解析できませんでした</h1></body></html>';
    } else {
        $parts = parse_url($matches[1]);
        parse_str((string) ($parts['query'] ?? ''), $query);
        if (($parts['path'] ?? '') !== '/callback') {
            $statusLine = 'HTTP/1.1 404 Not Found';
            $body = '<html><body><h1>callback pathが正しくありません</h1></body></html>';
        }
    }

    while (($line = fgets($connection)) !== false) {
        if (trim($line) === '') {
            break;
        }
    }

    fwrite($connection, $statusLine . "\r\n");
    fwrite($connection, "Content-Type: text/html; charset=UTF-8\r\n");
    fwrite($connection, 'Content-Length: ' . strlen($body) . "\r\n");
    fwrite($connection, "Connection: close\r\n\r\n");
    fwrite($connection, $body);
    fclose($connection);

    return $query;
}

function exchangeCodeForToken(array $client, string $code, string $redirectUri, string $codeVerifier, ?string $caBundlePath): array
{
    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_POSTFIELDS => http_build_query([
            'client_id' => $client['client_id'],
            'client_secret' => $client['client_secret'],
            'code' => $code,
            'code_verifier' => $codeVerifier,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $redirectUri,
        ], '', '&', PHP_QUERY_RFC3986),
    ]);
    if ($caBundlePath !== null) {
        curl_setopt($ch, CURLOPT_CAINFO, $caBundlePath);
    }

    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);

    if ($body === false || $status < 200 || $status >= 300) {
        $summary = $body === false ? $error : apiErrorSummary((string) $body);
        throw new RuntimeException("OAuthトークン交換に失敗しました: status={$status}, error={$summary}");
    }

    $decoded = json_decode((string) $body, true);
    if (!is_array($decoded) || empty($decoded['access_token'])) {
        throw new RuntimeException('OAuthトークンレスポンスを解析できません');
    }

    return $decoded;
}

function writeJsonFile(string $path, array $data): void
{
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
        throw new RuntimeException("トークン保存ディレクトリを作成できません: {$dir}");
    }

    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        throw new RuntimeException('トークンJSONを生成できません');
    }

    if (file_put_contents($path, $json . PHP_EOL, LOCK_EX) === false) {
        throw new RuntimeException("トークンファイルを書き込めません: {$path}");
    }

    @chmod($path, 0600);
}

function base64Url(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function apiErrorSummary(string $body): string
{
    $decoded = json_decode($body, true);
    if (is_array($decoded)) {
        $error = $decoded['error'] ?? null;
        if (is_scalar($error) && (string) $error !== '') {
            return truncateErrorSummary((string) $error);
        }

        if (is_array($error)) {
            return truncateErrorSummary(implode(' / ', array_filter([
                $error['status'] ?? null,
                $error['reason'] ?? null,
                $error['message'] ?? null,
            ], static fn ($value): bool => is_scalar($value) && (string) $value !== '')));
        }

        return truncateErrorSummary(implode(' / ', array_filter([
            $decoded['error_description'] ?? null,
            $decoded['message'] ?? null,
        ], static fn ($value): bool => is_scalar($value) && (string) $value !== '')));
    }

    return 'レスポンス本文はログ出力しません';
}

function truncateErrorSummary(string $summary): string
{
    $summary = trim(preg_replace('/\s+/u', ' ', $summary) ?? $summary);
    if ($summary === '') {
        return '詳細なし';
    }

    return mb_strlen($summary, 'UTF-8') > 200 ? mb_substr($summary, 0, 200, 'UTF-8') . '...' : $summary;
}
