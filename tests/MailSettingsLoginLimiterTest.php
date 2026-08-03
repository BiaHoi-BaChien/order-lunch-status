<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/MailSettingsLoginLimiter.php';

$statePath = sys_get_temp_dir() . '/mail-settings-login-limiter-test-' . bin2hex(random_bytes(8)) . '.json';

try {
    $limiter = new MailSettingsLoginLimiter($statePath, 3, 60, 120, 10, 30);
    $client = '203.0.113.10';

    assertSame(0, $limiter->retryAfter($client, 1000));
    assertSame(1, $limiter->recordFailure($client, 1000));
    assertSame(1, $limiter->retryAfter($client, 1000));
    assertSame(0, $limiter->retryAfter($client, 1001));

    assertSame(2, $limiter->recordFailure($client, 1001));
    assertSame(2, $limiter->retryAfter($client, 1001));
    assertSame(0, $limiter->retryAfter($client, 1003));

    assertSame(120, $limiter->recordFailure($client, 1003));
    assertSame(120, $limiter->retryAfter($client, 1003));
    assertSame(1, $limiter->retryAfter($client, 1122));
    assertSame(0, $limiter->retryAfter($client, 1123));

    $limiter->recordSuccess($client, 1123);
    assertSame(0, $limiter->retryAfter($client, 1123));

    $globalLimiter = new MailSettingsLoginLimiter($statePath, 10, 60, 120, 3, 30);
    assertSame(1, $globalLimiter->recordFailure('198.51.100.1', 2000));
    assertSame(1, $globalLimiter->recordFailure('198.51.100.2', 2000));
    assertSame(30, $globalLimiter->recordFailure('198.51.100.3', 2000));
    assertSame(30, $globalLimiter->retryAfter('198.51.100.99', 2000));
    assertSame(1, $globalLimiter->retryAfter('198.51.100.99', 2029));
    assertSame(0, $globalLimiter->retryAfter('198.51.100.99', 2030));

    echo "Mail settings login limiter test passed\n";
} finally {
    @unlink($statePath);
}

function assertSame(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException('Assertion failed: expected=' . var_export($expected, true) . ', actual=' . var_export($actual, true));
    }
}
