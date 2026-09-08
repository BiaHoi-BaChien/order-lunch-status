<?php

declare(strict_types=1);

$source = (string) file_get_contents(__DIR__ . '/../mail_settings.php');

foreach (["'松屋' => [", "'RAMEN KIMURA' => [", "'MAIL_RAMEN_KIMURA_ORDER_FROM'", "'MAIL_RAMEN_KIMURA_ORDER_SUBJECT'", "'MAIL_RAMEN_KIMURA_RECEIPT_FROM'", "'MAIL_RAMEN_KIMURA_RECEIPT_SUBJECT'"] as $expected) {
    if (!str_contains($source, $expected)) {
        throw new RuntimeException("Assertion failed: {$expected} not found");
    }
}

foreach (['MAIL_MATSUYA_FIELD_DATE_LABELS', 'MAIL_MATSUYA_FIELD_TICKET_LABELS', 'MAIL_MATSUYA_FIELD_ITEM_LABELS', 'MAIL_MATSUYA_FIELD_SIZE_LABELS', 'MAIL_MATSUYA_FIELD_NOTE_LABELS', 'MAIL_MATSUYA_FIELD_NOTE_APPEND_LABELS', 'MAIL_MATSUYA_KNOWN_ITEMS'] as $obsolete) {
    if (str_contains($source, $obsolete)) {
        throw new RuntimeException("Assertion failed: obsolete setting {$obsolete} found");
    }
}

echo "Mail settings layout test passed\n";
