<?php

declare(strict_types=1);

final class MailSettingsLoginLimiter
{
    public function __construct(
        private readonly string $statePath,
        private readonly int $maxClientFailures = 5,
        private readonly int $windowSeconds = 900,
        private readonly int $lockoutSeconds = 900,
        private readonly int $maxGlobalFailures = 50,
        private readonly int $globalLockoutSeconds = 60
    ) {
        if ($maxClientFailures < 1 || $windowSeconds < 1 || $lockoutSeconds < 1
            || $maxGlobalFailures < 1 || $globalLockoutSeconds < 1) {
            throw new InvalidArgumentException('Login limiter values must be positive integers');
        }
    }

    /** @return array{authenticated: bool, limited: bool, retry_after: int} */
    public function attempt(string $clientKey, callable $verify, ?int $now = null): array
    {
        // Keep admission, password verification and accounting under the same lock.
        return $this->withState($now, function (array &$state, int $checkedAt) use ($clientKey, $verify, $now): array {
            $clientId = $this->clientId($clientKey);
            $retryAfter = $this->retryAfterFromState($state, $clientId, $checkedAt);
            if ($retryAfter > 0) {
                return ['authenticated' => false, 'limited' => true, 'retry_after' => $retryAfter];
            }
            if ($verify() === true) {
                unset($state['clients'][$clientId]);

                return ['authenticated' => true, 'limited' => false, 'retry_after' => 0];
            }

            // Lock wait and password verification must not consume the new backoff.
            $failedAt = $now ?? time();
            $state = $this->pruneState($state, $failedAt);
            $state['clients'][$clientId] ??= ['failures' => [], 'blocked_until' => 0];
            $state['clients'][$clientId]['failures'][] = $failedAt;
            $state['global']['failures'][] = $failedAt;

            $clientFailures = count($state['clients'][$clientId]['failures']);
            $clientDelay = $clientFailures >= $this->maxClientFailures
                ? $this->lockoutSeconds
                : min(2 ** ($clientFailures - 1), 30);
            $state['clients'][$clientId]['blocked_until'] = $failedAt + $clientDelay;

            if (count($state['global']['failures']) >= $this->maxGlobalFailures) {
                $state['global']['blocked_until'] = $failedAt + $this->globalLockoutSeconds;
            }

            return [
                'authenticated' => false,
                'limited' => false,
                'retry_after' => $this->retryAfterFromState($state, $clientId, $failedAt),
            ];
        });
    }

    private function clientId(string $clientKey): string
    {
        return hash('sha256', $clientKey === '' ? 'unknown' : $clientKey);
    }

    private function retryAfterFromState(array $state, string $clientId, int $now): int
    {
        $blockedUntil = (int) ($state['global']['blocked_until'] ?? 0);
        if (isset($state['clients'][$clientId])) {
            $blockedUntil = max($blockedUntil, (int) ($state['clients'][$clientId]['blocked_until'] ?? 0));
        }

        return max(0, $blockedUntil - $now);
    }

    private function withState(?int $now, callable $callback): mixed
    {
        $directory = dirname($this->statePath);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('ログイン試行状態の保存先を作成できません');
        }

        $handle = fopen($this->statePath, 'c+b');
        if ($handle === false) {
            throw new RuntimeException('ログイン試行状態を開けません');
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('ログイン試行状態をロックできません');
            }

            rewind($handle);
            $raw = stream_get_contents($handle);
            $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
            $state = is_array($decoded) ? $decoded : [];
            $now ??= time();
            $state = $this->pruneState($state, $now);

            $result = $callback($state, $now);
            $json = json_encode($state, JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                throw new RuntimeException('ログイン試行状態を生成できません');
            }

            rewind($handle);
            if (!ftruncate($handle, 0) || fwrite($handle, $json) === false || !fflush($handle)) {
                throw new RuntimeException('ログイン試行状態を保存できません');
            }

            return $result;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function pruneState(array $state, int $now): array
    {
        $cutoff = $now - $this->windowSeconds;
        $global = is_array($state['global'] ?? null) ? $state['global'] : [];
        $global['failures'] = $this->recentFailures($global['failures'] ?? [], $cutoff);
        $global['blocked_until'] = max(0, (int) ($global['blocked_until'] ?? 0));

        $clients = [];
        foreach (is_array($state['clients'] ?? null) ? $state['clients'] : [] as $clientId => $client) {
            if (!is_string($clientId) || !is_array($client)) {
                continue;
            }
            $failures = $this->recentFailures($client['failures'] ?? [], $cutoff);
            $blockedUntil = max(0, (int) ($client['blocked_until'] ?? 0));
            if ($failures !== [] || $blockedUntil > $now) {
                $clients[$clientId] = ['failures' => $failures, 'blocked_until' => $blockedUntil];
            }
        }

        return ['global' => $global, 'clients' => $clients];
    }

    /**
     * @return list<int>
     */
    private function recentFailures(mixed $failures, int $cutoff): array
    {
        if (!is_array($failures)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $failure): int => (int) $failure, $failures),
            static fn (int $failure): bool => $failure > $cutoff
        ));
    }
}
