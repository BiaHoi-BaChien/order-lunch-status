<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/GmailMessageAuthenticator.php';

$authenticator = new GmailMessageAuthenticator();
$authenticator->assertAuthentic(message(
    'Google Forms <forms-receipts-noreply@google.com>',
    'mx.google.com; dkim=pass header.d=google.com; dmarc=pass header.from=google.com'
), 'forms-receipts-noreply@google.com');

$authenticator->assertAuthentic(message(
    'Quynh <anh.nguyenquynh@matsuyafoods.com.vn>',
    'mx.google.com; dkim=permerror header.d=matsuyafoods.com.vn; spf=pass smtp.mailfrom=anh.nguyenquynh@matsuyafoods.com.vn'
), 'anh.nguyenquynh@matsuyafoods.com.vn');

$authenticator->assertAuthentic(message(
    'Receipt B <receipt-b@example.com>',
    'mx.google.com; spf=pass smtp.mailfrom=receipt-b@example.com'
), 'receipt-a@example.com|receipt-b@example.com');

$authenticator->assertAuthentic(message(
    'Receipt B <receipt-b@example.com>',
    'mx.google.com; spf=pass smtp.mailfrom=receipt-b@example.com'
), 'receipt-a@example.com｜receipt-b@example.com');

assertThrows(static fn () => $authenticator->assertAuthentic(message(
    'attacker@example.com',
    'mx.google.com; spf=pass smtp.mailfrom=attacker@example.com'
), 'receipt-a@example.com|receipt-b@example.com'));

assertThrows(static fn () => $authenticator->assertAuthentic(message(
    'anh.nguyenquynh@matsuyafoods.com.vn',
    'mx.google.com; spf=pass smtp.mailfrom=attacker@matsuyafoods.com.vn'
), 'anh.nguyenquynh@matsuyafoods.com.vn'));

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

foreach ([
    'dkim=fail header.d=trusted.example; dkim=pass header.d=attacker.example',
    'dkim=pass header.d=attacker.example; dkim=fail header.d=trusted.example',
    'dmarc=fail header.from=trusted.example; dmarc=pass header.from=attacker.example',
    'dkim=fail reason="dkim=pass header.d=trusted.example"',
    'dkim=fail (dkim=pass header.d=trusted.example)',
    'dkim=fail (outer (nested); spf=pass smtp.mailfrom=shop@trusted.example)',
    'dkim=fail reason="ignored; spf=pass smtp.mailfrom=shop@trusted.example"',
    'dkim=passive header.d=trusted.example',
    'dkim=pass header.d=trusted.example@attacker.example',
    'dkim=pass header.d=trusted.example.attacker.example',
    'dkim=pass header.d=attacker.example reason="header.d=trusted.example"',
    'dkim=pass header.d=trusted.example header.d=attacker.example',
    'spf=pass smtp.mailfrom=shop@trusted.example smtp.mailfrom=attacker@example.com',
    'spf=pass smtp.mailfrom=other@trusted.example',
    'dkim=pass header.d=trusted.example (unterminated',
    'dkim=pass header.d="trusted.example',
    'dkim/2=pass header.d=trusted.example',
    "dkim=pass header.d=trusted.example\ninvalid",
] as $result) {
    assertThrows(static fn () => $authenticator->assertAuthentic(
        message('shop@trusted.example', 'mx.google.com; ' . $result),
        'shop@trusted.example'
    ));
}

foreach ([
    'dkim=fail header.d=attacker.example; dkim=pass header.d=trusted.example',
    'dkim=pass header.d=trusted.example; dkim=fail header.d=attacker.example',
    'dmarc=fail header.from=attacker.example; dmarc=pass header.from=trusted.example',
    'DKIM = PASS header.d="TRUSTED.EXAMPLE" header.b=abc/123==',
    'dkim/1=pass (valid (nested) comment) header . d = mail.trusted.example.',
    'dkim=pass reason="comment; escaped \\" quote" header.d=trusted.example',
    'dkim=pass (escaped \\) comment) header.d=trusted.example',
    "dkim=pass\r\n\theader.d=trusted.example",
    'dkim=permerror header.d=trusted.example; spf=pass smtp.mailfrom="shop@trusted.example"',
] as $result) {
    $authenticator->assertAuthentic(
        message('shop@trusted.example', 'mx.google.com; ' . $result),
        'shop@trusted.example'
    );
}

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
