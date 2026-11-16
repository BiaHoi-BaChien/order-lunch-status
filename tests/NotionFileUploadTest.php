<?php

declare(strict_types=1);

// cURLの送信境界だけを子プロセスで置き換え、実際のCURLStringFileを検証する。
if (function_exists('curl_exec')) {
    $process = proc_open([
        PHP_BINARY,
        '-d', 'disable_functions=curl_setopt_array,curl_exec,curl_getinfo',
        __FILE__,
    ], [STDIN, STDOUT, STDERR], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot start file upload test process');
    }
    exit(proc_close($process));
}

if (!function_exists('curl_exec')) {
    function curl_setopt_array(CurlHandle $handle, array $options): bool
    {
        $GLOBALS['uploadedFile'] = $options[CURLOPT_POSTFIELDS]['file'];
        return true;
    }

    function curl_exec(CurlHandle $handle): string
    {
        return '{"status":"uploaded"}';
    }

    function curl_getinfo(CurlHandle $handle, int $option): int
    {
        return 200;
    }
}

require_once __DIR__ . '/../src/NotionClient.php';

$notion = new NotionClient('unused', 'unused', 'unused');
$method = new ReflectionMethod(NotionClient::class, 'sendFileUpload');
$data = "\x89PNG\r\n\x1a\n\0test-image";

foreach (['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif', 'image/webp' => 'webp'] as $mimeType => $extension) {
    $filename = 'ramen-kimura-qr.' . $extension;
    $method->invoke($notion, 'test-upload-id', $data, $mimeType, $filename);
    $file = $GLOBALS['uploadedFile'];
    assertSame(true, $file instanceof CURLStringFile);
    assertSame($data, $file->data);
    assertSame($filename, $file->postname);
    assertSame($mimeType, $file->mime);
}

echo "Notion file upload test passed\n";

function assertSame(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException('Assertion failed: expected=' . var_export($expected, true) . ', actual=' . var_export($actual, true));
    }
}
