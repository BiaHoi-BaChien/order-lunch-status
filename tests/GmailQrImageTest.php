<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Logger.php';
require_once __DIR__ . '/../src/GmailClient.php';

$gmail = new GmailClient('me', 'unused', 'unused', new Logger(sys_get_temp_dir() . '/order-lunch-status-test.log'));
$message = [
    'payload' => [
        'mimeType' => 'multipart/related',
        'parts' => [
            [
                'mimeType' => 'text/html',
                'body' => ['data' => base64Url('<p>送金用QR</p><img src="cid:payment" alt="QRコード">')],
            ],
            [
                'mimeType' => 'image/png',
                'filename' => 'logo.png',
                'headers' => [['name' => 'Content-ID', 'value' => '<logo>']],
                'body' => ['data' => base64Url('logo')],
            ],
            [
                'mimeType' => 'image/png',
                'filename' => 'image.png',
                'headers' => [['name' => 'Content-ID', 'value' => '<payment>']],
                'body' => ['data' => base64Url('qr-image')],
            ],
        ],
    ],
];

$image = $gmail->extractQrImage('message-id', $message);
assertSame('qr-image', $image['data']);
assertSame('image/png', $image['mime_type']);

$externalUrl = 'https://img.vietqr.io/image/test.png?amount=80000';
$externalImage = $gmail->extractQrImage('message-id', [
    'payload' => [
        'mimeType' => 'text/html',
        'body' => ['data' => base64Url('<img src="' . $externalUrl . '">')],
    ],
]);
assertSame($externalUrl, $externalImage['url']);

$missingImage = $gmail->extractQrImage('message-id', [
    'payload' => [
        'mimeType' => 'text/plain',
        'body' => ['data' => base64Url('QR画像なし')],
    ],
]);
assertSame(null, $missingImage);

echo "Gmail QR image test passed\n";

function base64Url(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function assertSame(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException('Assertion failed: expected=' . var_export($expected, true) . ', actual=' . var_export($actual, true));
    }
}
