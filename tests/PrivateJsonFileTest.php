<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/PrivateJsonFile.php';

$dir = sys_get_temp_dir() . '/order-lunch-status-private-json-' . bin2hex(random_bytes(8));
$path = $dir . '/token.json';
$oldUmask = umask(0000);
try {
    PrivateJsonFile::write($path, '{"token":"secret"}');
} finally {
    umask($oldUmask);
}

clearstatcache(true, $path);
assertSame(0600, fileperms($path) & 0777);
assertSame("{\"token\":\"secret\"}" . PHP_EOL, file_get_contents($path));

chmod($path, 0644);
PrivateJsonFile::write($path, '{"token":"updated"}');
clearstatcache(true, $path);
assertSame(0600, fileperms($path) & 0777);
assertSame("{\"token\":\"updated\"}" . PHP_EOL, file_get_contents($path));

unlink($path);
rmdir($dir);

echo "PrivateJsonFile test passed\n";

function assertSame(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException('Assertion failed: expected=' . var_export($expected, true) . ', actual=' . var_export($actual, true));
    }
}
