<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/GmailMessageAuthenticator.php';

$authenticator = new GmailMessageAuthenticator();
$authenticator->assertAuthentic(message(
    'Google Forms <forms-receipts-noreply@google.com>',
    'mx.google.com; dkim=pass header.d=google.com; dmarc=pass header.from=google.com'
), 'forms-receipts-noreply@google.com');

assertThrows(static fn () => $authenticator->assertAuthentic(message(
    'attacker@example.com',
    'mx.google.com; dmarc=pass header.from=example.com'
), 'forms-receipts-noreply@google.com'));

assertThrows(static fn () => $authenticator->assertAuthentic(message(
    'forms-receipts-noreply@google.com',
    'attacker.example; dmarc=pass header.from=google.com'
), 'forms-receipts-noreply@google.com'));

assertThrows(static fn () => $authenticator->assertAuthentic(message(
    'forms-receipts-noreply@google.com',
    'mx.google.com; dkim=fail header.d=google.com; dmarc=fail header.from=google.com'
), 'forms-receipts-noreply@google.com'));

echo "GmailMessageAuthenticator test passed\n";

/** @return array<string, mixed> */
function message(string $from, string $authenticationResults): array
{
    return ['payload' => ['headers' => [
        ['name' => 'From', 'value' => $from],
        ['name' => 'Authentication-Results', 'value' => $authenticationResults],
    ]]];
}

function assertThrows(Closure $callback): void
{
    try {
        $callback();
    } catch (RuntimeException) {
        return;
    }

    throw new RuntimeException('Assertion failed: expected RuntimeException');
}
