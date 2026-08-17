<?php

declare(strict_types=1);

final class GmailMessageAuthenticator
{
    /**
     * @param array<string, mixed> $message
     */
    public function assertAuthentic(array $message, string $expectedSender): void
    {
        $expectedAddress = $this->mailboxAddress($expectedSender);
        $headers = $message['payload']['headers'] ?? [];
        if (!is_array($headers)) {
            throw new RuntimeException('メールヘッダーを確認できません');
        }

        $fromAddress = null;
        $authenticationResults = [];
        foreach ($headers as $header) {
            if (!is_array($header)) {
                continue;
            }

            $name = strtolower(trim((string) ($header['name'] ?? '')));
            $value = trim((string) ($header['value'] ?? ''));
            if ($name === 'from' && $fromAddress === null) {
                $fromAddress = $this->mailboxAddress($value);
            } elseif ($name === 'authentication-results' && $value !== '') {
                $authenticationResults[] = $value;
            }
        }

        if ($fromAddress === null || !hash_equals($expectedAddress, $fromAddress)) {
            throw new RuntimeException('メール送信元が許可されたアドレスと一致しません');
        }

        $expectedDomain = substr($expectedAddress, (int) strrpos($expectedAddress, '@') + 1);
        foreach ($authenticationResults as $result) {
            if ($this->isTrustedPass($result, $expectedAddress, $expectedDomain)) {
                return;
            }
        }

        throw new RuntimeException('GmailによるDMARC、DKIMまたはSPF認証の成功を確認できません');
    }

    private function mailboxAddress(string $value): string
    {
        $value = trim($value);
        if (preg_match('/<([^<>]+)>\s*$/', $value, $matches) === 1) {
            $value = trim($matches[1]);
        }

        if (str_contains($value, ',') || str_contains($value, "\r") || str_contains($value, "\n")) {
            throw new RuntimeException('メール送信元アドレスの形式が不正です');
        }

        $value = strtolower($value);
        if (filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('メール送信元アドレスの形式が不正です');
        }

        return $value;
    }

    private function isTrustedPass(string $result, string $expectedAddress, string $expectedDomain): bool
    {
        $normalized = strtolower(trim($result));
        if (preg_match('/^mx\.google\.com\s*;/', $normalized) !== 1) {
            return false;
        }

        if (str_contains($normalized, 'dmarc=pass')
            && preg_match('/\bheader\.from=([a-z0-9.-]+)/', $normalized, $matches) === 1
            && $this->domainAligns($matches[1], $expectedDomain)) {
            return true;
        }

        if (str_contains($normalized, 'dkim=pass')
            && preg_match('/\bheader\.d=([a-z0-9.-]+)/', $normalized, $matches) === 1
            && $this->domainAligns($matches[1], $expectedDomain)) {
            return true;
        }

        return preg_match('/(?:^|;)\s*spf=pass\b[^;]*\bsmtp\.mailfrom=([^;\s]+)/', $normalized, $matches) === 1
            && filter_var($matches[1], FILTER_VALIDATE_EMAIL) !== false
            && hash_equals($expectedAddress, $matches[1]);
    }

    private function domainAligns(string $authenticatedDomain, string $expectedDomain): bool
    {
        $authenticatedDomain = rtrim(strtolower($authenticatedDomain), '.');
        $expectedDomain = rtrim(strtolower($expectedDomain), '.');

        return $authenticatedDomain === $expectedDomain
            || str_ends_with($authenticatedDomain, '.' . $expectedDomain);
    }
}
