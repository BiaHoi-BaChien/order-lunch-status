<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/EnvFileEditor.php';

$path = sys_get_temp_dir() . '/order-lunch-status-env-' . bin2hex(random_bytes(6)) . '.env';
file_put_contents($path, implode(PHP_EOL, [
    'NOTION_API_KEY=secret_xxx',
    'MAIL_MATSUYA_ORDER_FROM=forms-receipts-noreply@google.com',
    'MAIL_MATSUYA_RECEIPT_FROM=receipt-a@example.com|receipt-b@example.com',
    '# comment',
    '',
]) . PHP_EOL);

try {
    $values = EnvFileEditor::readValues($path);
    assertSame('forms-receipts-noreply@google.com', $values['MAIL_MATSUYA_ORDER_FROM'] ?? null);
    assertSame(['receipt-a@example.com', 'receipt-b@example.com'], EnvFileEditor::envToList($values['MAIL_MATSUYA_RECEIPT_FROM'] ?? ''));

    EnvFileEditor::updateValues($path, [
        'MAIL_MATSUYA_ORDER_FROM' => '',
        'MAIL_MATSUYA_RECEIPT_FROM' => EnvFileEditor::listToEnv(['receipt-a@example.com', 'receipt-c@example.com', '']),
        'MAIL_SETTINGS_TEST_VALUE' => 'pass#word',
    ]);

    $updated = EnvFileEditor::readValues($path);
    assertSame('secret_xxx', $updated['NOTION_API_KEY'] ?? null);
    assertSame('', $updated['MAIL_MATSUYA_ORDER_FROM'] ?? null);
    assertSame('receipt-a@example.com|receipt-c@example.com', $updated['MAIL_MATSUYA_RECEIPT_FROM'] ?? null);
    assertSame('pass#word', $updated['MAIL_SETTINGS_TEST_VALUE'] ?? null);

    $content = (string) file_get_contents($path);
    assertContains('NOTION_API_KEY=secret_xxx', $content);
    assertContains('MAIL_SETTINGS_TEST_VALUE="pass#word"', $content);
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
