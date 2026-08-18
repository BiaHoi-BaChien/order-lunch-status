<?php

declare(strict_types=1);

$source = (string) file_get_contents(__DIR__ . '/../mail_settings.php');

foreach (["'松屋' => [", "'RAMEN KIMURA' => [", "'MAIL_KIMURA_ORDER_FROM'", "'MAIL_KIMURA_ORDER_SUBJECT'"] as $expected) {
    if (!str_contains($source, $expected)) {
        throw new RuntimeException("Assertion failed: {$expected} not found");
    }
}

echo "Mail settings layout test passed\n";
