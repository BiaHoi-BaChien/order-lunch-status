<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/MailSettingsLoginLimiter.php';

$statePath = sys_get_temp_dir() . '/mail-settings-login-limiter-test-' . bin2hex(random_bytes(8)) . '.json';

try {
    $limiter = new MailSettingsLoginLimiter($statePath, 3, 60, 120, 10, 30);
    $client = '203.0.113.10';
    $checks = 0;
    $wrongPassword = static function () use (&$checks): bool {
        $checks++;
        return false;
    };
    $mustNotVerify = static fn (): bool => throw new RuntimeException('Blocked attempt reached password verification');

    assertSame(failed(1), $limiter->attempt($client, $wrongPassword, 1000));
    for ($i = 0; $i < 7; $i++) {
        assertSame(blocked(1), $limiter->attempt($client, $mustNotVerify, 1000));
    }
    assertSame(1, $checks);
    assertSame(failed(2), $limiter->attempt($client, $wrongPassword, 1001));
    assertSame(blocked(2), $limiter->attempt($client, $mustNotVerify, 1001));
    assertSame(failed(120), $limiter->attempt($client, $wrongPassword, 1003));
    assertSame(blocked(120), $limiter->attempt($client, $mustNotVerify, 1003));
    assertSame(blocked(1), $limiter->attempt($client, $mustNotVerify, 1122));
    assertSame(3, $checks);
    assertSame(succeeded(), $limiter->attempt($client, static fn (): bool => true, 1123));

    // An existing state file retains both scopes; success clears only this client.
    file_put_contents($statePath, json_encode([
        'clients' => [hash('sha256', $client) => ['failures' => [1199], 'blocked_until' => 1200]],
        'global' => ['failures' => [1199], 'blocked_until' => 0],
    ], JSON_THROW_ON_ERROR));
    $restored = new MailSettingsLoginLimiter($statePath, 3, 60, 120, 10, 30);
    assertSame(blocked(1), $restored->attempt($client, $mustNotVerify, 1199));
    assertSame(succeeded(), $restored->attempt($client, static fn (): bool => true, 1200));
    $state = json_decode((string) file_get_contents($statePath), true, 512, JSON_THROW_ON_ERROR);
    assertSame(false, isset($state['clients'][hash('sha256', $client)]));
    assertSame([1199], $state['global']['failures']);

    $globalLimiter = new MailSettingsLoginLimiter($statePath, 10, 60, 120, 3, 30);
    assertSame(failed(1), $globalLimiter->attempt('198.51.100.1', $wrongPassword, 2000));
    assertSame(failed(1), $globalLimiter->attempt('198.51.100.2', $wrongPassword, 2000));
    assertSame(failed(30), $globalLimiter->attempt('198.51.100.3', $wrongPassword, 2000));
    assertSame(blocked(30), $globalLimiter->attempt('198.51.100.99', $mustNotVerify, 2000));
    assertSame(blocked(1), $globalLimiter->attempt('198.51.100.99', $mustNotVerify, 2029));
    assertSame(succeeded(), $globalLimiter->attempt('198.51.100.99', static fn (): bool => true, 2030));

    // A verifier failure must propagate, release the lock and never authenticate.
    try {
        $globalLimiter->attempt('198.51.100.99', static fn (): bool => throw new LogicException('Verifier unavailable'), 2100);
        throw new RuntimeException('Expected verifier failure');
    } catch (LogicException) {
    }
    assertSame(failed(1), $globalLimiter->attempt('198.51.100.99', $wrongPassword, 2100));

    $finishedAt = 0;
    $slowFailure = $globalLimiter->attempt('198.51.100.100', static function () use (&$finishedAt): bool {
        usleep(1100000);
        $finishedAt = time();
        return false;
    });
    assertSame(failed(1), $slowFailure);
    $state = json_decode((string) file_get_contents($statePath), true, 512, JSON_THROW_ON_ERROR);
    assertSame(true, $state['clients'][hash('sha256', '198.51.100.100')]['blocked_until'] >= $finishedAt + 1);

    echo "Mail settings login limiter test passed\n";
} finally {
    @unlink($statePath);
}

function failed(int $delay): array
{
    return ['authenticated' => false, 'limited' => false, 'retry_after' => $delay];
}

function blocked(int $delay): array
{
    return ['authenticated' => false, 'limited' => true, 'retry_after' => $delay];
}

function succeeded(): array
{
    return ['authenticated' => true, 'limited' => false, 'retry_after' => 0];
}

function assertSame(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException('Assertion failed: expected=' . var_export($expected, true) . ', actual=' . var_export($actual, true));
    }
}
