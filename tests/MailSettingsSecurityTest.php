<?php

declare(strict_types=1);

$source = (string) file_get_contents(__DIR__ . '/../mail_settings.php');

assertContains('if (!$passwordConfigured)', $source);
assertContains('session_regenerate_id(true)', $source);
assertContains("return 'mail_settings.php';", $source);
assertNotContains('function isAllowedClient', $source);
assertNotContains("\$_SERVER['REQUEST_URI']", $source);

echo "MailSettings security test passed\n";

function assertContains(string $expected, string $actual): void
{
    if (!str_contains($actual, $expected)) {
        throw new RuntimeException("Assertion failed: {$expected} not found");
    }
}

function assertNotContains(string $unexpected, string $actual): void
{
    if (str_contains($actual, $unexpected)) {
        throw new RuntimeException("Assertion failed: {$unexpected} was found");
    }
}
