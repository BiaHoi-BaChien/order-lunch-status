<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/MailSettingsAuth.php';

putenv('MAIL_SETTINGS_PASSWORD_HASH');

$hash = password_hash('secret-pass', PASSWORD_DEFAULT);
$auth = MailSettingsAuth::fromEnvironment([
    'MAIL_SETTINGS_PASSWORD_HASH' => $hash,
]);
assertSame(true, $auth->isConfigured());
assertSame(true, $auth->verify('secret-pass'));
assertSame(false, $auth->verify('wrong-pass'));

$auth = MailSettingsAuth::fromEnvironment([]);
assertSame(false, $auth->isConfigured());
assertSame(false, $auth->verify('anything'));

echo "Mail settings auth test passed\n";

function assertSame(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException('Assertion failed: expected=' . var_export($expected, true) . ', actual=' . var_export($actual, true));
    }
}
