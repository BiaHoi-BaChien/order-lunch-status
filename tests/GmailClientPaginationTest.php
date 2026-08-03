<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Logger.php';
require_once __DIR__ . '/../src/GmailClient.php';

$paths = [];
$transport = static function (string $method, string $path, ?array $payload) use (&$paths): array {
    $paths[] = $path;
    parse_str((string) parse_url($path, PHP_URL_QUERY), $query);
    $offset = isset($query['pageToken']) ? (int) $query['pageToken'] : 0;
    $messages = [];
    for ($i = $offset; $i < min($offset + 100, 150); $i++) {
        $messages[] = ['id' => "message-{$i}", 'threadId' => "thread-{$i}"];
    }

    return [
        'messages' => $messages,
        'nextPageToken' => $offset + 100 < 150 ? (string) ($offset + 100) : null,
    ];
};

$client = new GmailClient('me', 'unused', 'unused', new Logger(sys_get_temp_dir() . '/gmail-client-pagination-test.log'), null, $transport);
$messages = $client->searchMessages('subject:test', 120);

assertSame(120, count($messages));
assertSame(2, count($paths));
assertContains('maxResults=100', $paths[0]);
assertContains('maxResults=20', $paths[1]);

echo "GmailClient pagination test passed\n";

function assertSame(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException('Assertion failed: expected=' . var_export($expected, true) . ', actual=' . var_export($actual, true));
    }
}

function assertContains(string $expected, string $actual): void
{
    if (!str_contains($actual, $expected)) {
        throw new RuntimeException("Assertion failed: {$expected} not found in {$actual}");
    }
}
