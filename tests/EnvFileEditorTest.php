<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/EnvFileEditor.php';

$path = sys_get_temp_dir() . '/order-lunch-status-env-' . bin2hex(random_bytes(6)) . '.env';
file_put_contents($path, implode(PHP_EOL, [
    'NOTION_API_KEY=secret_xxx',
    'MAIL_ORDER_FROM=forms-receipts-noreply@google.com',
    'MAIL_FIELD_ITEM_LABELS=品名|メニュー',
    '# comment',
    '',
]) . PHP_EOL);

try {
    $values = EnvFileEditor::readValues($path);
    assertSame('forms-receipts-noreply@google.com', $values['MAIL_ORDER_FROM'] ?? null);
    assertSame(['品名', 'メニュー'], EnvFileEditor::envToList($values['MAIL_FIELD_ITEM_LABELS'] ?? ''));

    EnvFileEditor::updateValues($path, [
        'MAIL_ORDER_FROM' => '',
        'MAIL_FIELD_ITEM_LABELS' => EnvFileEditor::listToEnv(['品名', '注文したお弁当', '']),
        'MAIL_SETTINGS_PASSWORD' => 'pass#word',
    ]);

    $updated = EnvFileEditor::readValues($path);
    assertSame('secret_xxx', $updated['NOTION_API_KEY'] ?? null);
    assertSame('', $updated['MAIL_ORDER_FROM'] ?? null);
    assertSame('品名|注文したお弁当', $updated['MAIL_FIELD_ITEM_LABELS'] ?? null);
    assertSame('pass#word', $updated['MAIL_SETTINGS_PASSWORD'] ?? null);

    $content = (string) file_get_contents($path);
    assertContains('NOTION_API_KEY=secret_xxx', $content);
    assertContains('MAIL_SETTINGS_PASSWORD="pass#word"', $content);
} finally {
    if (is_file($path)) {
        unlink($path);
    }
}

echo "Env file editor test passed\n";

function assertSame(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException('Assertion failed: expected=' . var_export($expected, true) . ', actual=' . var_export($actual, true));
    }
}

function assertContains(string $needle, string $haystack): void
{
    if (!str_contains($haystack, $needle)) {
        throw new RuntimeException('Assertion failed: missing=' . var_export($needle, true));
    }
}
