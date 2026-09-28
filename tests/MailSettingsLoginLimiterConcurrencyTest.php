<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/MailSettingsLoginLimiter.php';

if (($argv[1] ?? '') === '--worker') {
    [$script, $mode, $directory, $client, $globalLimit, $worker] = $argv;
    file_put_contents($directory . '/ready-' . $worker, 'ready');
    waitFor(static fn (): bool => is_file($directory . '/start'));
    $limiter = new MailSettingsLoginLimiter($directory . '/state.json', 5, 900, 900, (int) $globalLimit, 60);
    $result = $limiter->attempt($client, static function () use ($directory, $worker): bool {
        file_put_contents($directory . '/checked-' . $worker, 'checked');
        usleep(100000);
        return false;
    }, 1000);
    echo json_encode($result, JSON_THROW_ON_ERROR);
    exit;
}

// Separate processes share only the limiter file, like independent PHP sessions.
runConcurrent(false, 50, 1);
runConcurrent(true, 3, 3);
echo "Mail settings login limiter concurrency test passed\n";

function runConcurrent(bool $distinctClients, int $globalLimit, int $expectedChecks): void
{
    $directory = sys_get_temp_dir() . '/mail-settings-concurrency-' . bin2hex(random_bytes(8));
    if (!mkdir($directory, 0700)) {
        throw new RuntimeException('Cannot create test directory');
    }
    $processes = [];
    try {
        for ($i = 0; $i < 8; $i++) {
            $client = $distinctClients ? '203.0.113.' . ($i + 1) : '203.0.113.1';
            $process = proc_open(
                [PHP_BINARY, __FILE__, '--worker', $directory, $client, (string) $globalLimit, (string) $i],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes
            );
            if (!is_resource($process)) {
                throw new RuntimeException('Cannot start worker');
            }
            fclose($pipes[0]);
            $processes[] = [$process, $pipes];
        }
        waitFor(static fn (): bool => count(glob($directory . '/ready-*') ?: []) === 8);
        file_put_contents($directory . '/start', 'start');
        waitFor(static function () use ($processes): bool {
            $running = false;
            foreach ($processes as [$process]) {
                $running = proc_get_status($process)['running'] || $running;
            }
            return !$running;
        });
        $admitted = 0;
        foreach ($processes as [$process, $pipes]) {
            $output = stream_get_contents($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            if ($errors !== '') {
                throw new RuntimeException('Worker failed: ' . $errors);
            }
            $result = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
            if ($result['authenticated'] !== false || $result['retry_after'] < 1) {
                throw new RuntimeException('Unexpected worker result');
            }
            $admitted += $result['limited'] ? 0 : 1;
        }
        $checks = count(glob($directory . '/checked-*') ?: []);
        $state = json_decode((string) file_get_contents($directory . '/state.json'), true, 512, JSON_THROW_ON_ERROR);
        $clientFailures = array_sum(array_map(static fn (array $client): int => count($client['failures']), $state['clients']));
        if ($checks !== $expectedChecks || $admitted !== $checks
            || count($state['global']['failures']) !== $checks || $clientFailures !== $checks) {
            throw new RuntimeException('Concurrent verification bypassed admission or lost failure accounting');
        }
    } finally {
        foreach ($processes as [$process, $pipes]) {
            if (proc_get_status($process)['running']) {
                proc_terminate($process);
            }
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        }
        foreach (glob($directory . '/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($directory);
    }
}

function waitFor(callable $ready): void
{
    $deadline = microtime(true) + 10;
    do {
        clearstatcache();
        if ($ready()) {
            return;
        }
        usleep(10000);
    } while (microtime(true) < $deadline);

    throw new RuntimeException('Worker synchronization timed out');
}
