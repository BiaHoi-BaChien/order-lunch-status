<?php

declare(strict_types=1);

final class GmailClient
{
    private ?string $accessToken = null;

    public function __construct(
        private readonly string $userId,
        private readonly string $credentialsPath,
        private readonly string $tokenPath,
        private readonly Logger $logger
    ) {
    }

    /**
     * @return array<int, array{id:string,threadId:string}>
     */
    public function searchMessages(string $query): array
    {
        $messages = [];
        $pageToken = null;

        do {
            $params = ['q' => $query, 'maxResults' => 100];
            if ($pageToken !== null) {
                $params['pageToken'] = $pageToken;
            }

            $response = $this->request('GET', '/messages?' . http_build_query($params));
            foreach (($response['messages'] ?? []) as $message) {
                if (isset($message['id'], $message['threadId'])) {
                    $messages[] = ['id' => $message['id'], 'threadId' => $message['threadId']];
                }
            }

            $pageToken = $response['nextPageToken'] ?? null;
        } while ($pageToken !== null);

        return $messages;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMessage(string $messageId): array
    {
        return $this->request('GET', '/messages/' . rawurlencode($messageId) . '?format=full');
    }

    public function messageUrl(string $messageId): string
    {
        return "https://mail.google.com/mail/u/0/#inbox/{$messageId}";
    }

    /**
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, ?array $payload = null): array
    {
        $url = 'https://gmail.googleapis.com/gmail/v1/users/' . rawurlencode($this->userId) . $path;
        $headers = [
            'Authorization: Bearer ' . $this->getAccessToken(),
            'Accept: application/json',
        ];

        $response = $this->curlJson($method, $url, $headers, $payload);
        if ($response['status'] === 401) {
            $this->accessToken = null;
            $response = $this->curlJson($method, $url, [
                'Authorization: Bearer ' . $this->refreshAccessToken(),
                'Accept: application/json',
            ], $payload);
        }

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new RuntimeException('Gmail APIエラー: status=' . $response['status'] . ', body=' . $response['body']);
        }

        $decoded = json_decode($response['body'], true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Gmail APIレスポンスJSONの解析に失敗しました');
        }

        return $decoded;
    }

    private function getAccessToken(): string
    {
        if ($this->accessToken !== null) {
            return $this->accessToken;
        }

        $token = $this->readJsonFile($this->tokenPath);
        if (isset($token['access_token'], $token['expires_at']) && (int) $token['expires_at'] > time() + 60) {
            return $this->accessToken = (string) $token['access_token'];
        }

        return $this->refreshAccessToken();
    }

    private function refreshAccessToken(): string
    {
        $credentials = $this->readJsonFile($this->credentialsPath);
        $token = $this->readJsonFile($this->tokenPath);
        $client = $credentials['installed'] ?? $credentials['web'] ?? $credentials;

        foreach (['client_id', 'client_secret'] as $key) {
            if (empty($client[$key])) {
                throw new RuntimeException("Gmail OAuth credentialsに {$key} がありません");
            }
        }
        if (empty($token['refresh_token'])) {
            throw new RuntimeException('gmail_token.jsonに refresh_token がありません');
        }

        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_POSTFIELDS => http_build_query([
                'client_id' => $client['client_id'],
                'client_secret' => $client['client_secret'],
                'refresh_token' => $token['refresh_token'],
                'grant_type' => 'refresh_token',
            ]),
        ]);
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false || $status < 200 || $status >= 300) {
            throw new RuntimeException("Gmail API認証失敗: status={$status}, error={$error}, body={$body}");
        }

        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded) || empty($decoded['access_token'])) {
            throw new RuntimeException('Gmail OAuthトークンレスポンスの解析に失敗しました');
        }

        $token['access_token'] = $decoded['access_token'];
        $token['expires_at'] = time() + (int) ($decoded['expires_in'] ?? 3600);
        file_put_contents($this->tokenPath, json_encode($token, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->logger->info('Gmail access_tokenを更新しました');

        return $this->accessToken = (string) $decoded['access_token'];
    }

    /**
     * @return array<string, mixed>
     */
    private function readJsonFile(string $path): array
    {
        if (!is_file($path)) {
            throw new RuntimeException("Gmail認証ファイルがありません: {$path}");
        }

        if (filesize($path) === 0) {
            throw new RuntimeException("Gmail認証ファイルが空です: {$path}。初回は php gmail_auth.php を実行してください");
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded)) {
            throw new RuntimeException("JSONファイルを解析できません: {$path}。初回は php gmail_auth.php を実行して再生成してください");
        }

        return $decoded;
    }

    /**
     * @return array{status:int,body:string}
     */
    private function curlJson(string $method, string $url, array $headers, ?array $payload): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => array_merge($headers, ['Content-Type: application/json']),
        ]);
        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException("Gmail API通信失敗: {$error}");
        }

        return ['status' => (int) $status, 'body' => (string) $body];
    }
}
