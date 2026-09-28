<?php

declare(strict_types=1);

final class GmailMessageAuthenticator
{
    /**
     * @param array<string, mixed> $message
     */
    public function assertAuthentic(array $message, string $expectedSender): void
    {
        $expectedAddresses = array_values(array_filter(
            array_map('trim', preg_split('/[|｜]/u', $expectedSender) ?: []),
            static fn (string $address): bool => $address !== ''
        ));
        $expectedAddresses = array_map($this->mailboxAddress(...), $expectedAddresses);
        if ($expectedAddresses === []) {
            throw new RuntimeException('メール送信元アドレスの形式が不正です');
        }

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

        $expectedAddress = null;
        foreach ($expectedAddresses as $address) {
            if ($fromAddress !== null && hash_equals($address, $fromAddress)) {
                $expectedAddress = $address;
                break;
            }
        }
        if ($expectedAddress === null) {
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
        $clauses = $this->authenticationResultClauses(strtolower($result));
        if ($clauses === [] || preg_match('/^mx\.google\.com(?:\s+1)?$/D', trim(array_shift($clauses))) !== 1) {
            return false;
        }

        foreach ($clauses as $clause) {
            if (preg_match('/^\s*(dkim|dmarc|spf)(?:\s*\/\s*1)?\s*=\s*pass(?=\s|$)/', $clause, $method) !== 1) {
                continue;
            }
            $properties = $this->resultProperties(substr($clause, strlen($method[0])));
            if ($properties === null) {
                continue;
            }

            $identity = $properties[match ($method[1]) {
                'dkim' => 'header.d',
                'dmarc' => 'header.from',
                'spf' => 'smtp.mailfrom',
            }] ?? '';
            if ($method[1] === 'spf') {
                if (filter_var($identity, FILTER_VALIDATE_EMAIL) !== false && hash_equals($expectedAddress, $identity)) {
                    return true;
                }
            } elseif (filter_var(rtrim($identity, '.'), FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false
                && $this->domainAligns($identity, $expectedDomain)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function authenticationResultClauses(string $value): array
    {
        // RFC 8601: semicolons inside comments or quoted values are not result boundaries.
        $value = preg_replace('/\r\n[ \t]+/', ' ', $value) ?? '';
        if (preg_match('/[\x00-\x08\x0a-\x1f\x7f]/', $value) === 1) {
            return [];
        }
        $clauses = [];
        $clause = '';
        $commentDepth = 0;
        $quoted = false;
        $escaped = false;
        for ($i = 0, $length = strlen($value); $i < $length; $i++) {
            $char = $value[$i];
            if ($escaped) {
                if ($commentDepth === 0) {
                    $clause .= $char;
                }
                $escaped = false;
            } elseif ($char === '\\' && ($quoted || $commentDepth > 0)) {
                if ($commentDepth === 0) {
                    $clause .= $char;
                }
                $escaped = true;
            } elseif ($commentDepth > 0) {
                if ($char === '(') {
                    $commentDepth++;
                } elseif ($char === ')') {
                    $commentDepth--;
                }
            } elseif ($char === '"') {
                $quoted = !$quoted;
                $clause .= $char;
            } elseif ($quoted) {
                $clause .= $char;
            } elseif ($char === '(') {
                $commentDepth = 1;
                $clause .= ' ';
            } elseif ($char === ')') {
                return [];
            } elseif ($char === ';') {
                $clauses[] = $clause;
                $clause = '';
            } else {
                $clause .= $char;
            }
        }
        if ($quoted || $commentDepth > 0 || $escaped) {
            return [];
        }
        $clauses[] = $clause;

        return $clauses;
    }

    /** @return array<string, string>|null */
    private function resultProperties(string $value): ?array
    {
        $properties = [];
        $offset = 0;
        $pattern = '~\G\s+([a-z][a-z0-9_-]*(?:\s*\.\s*[a-z][a-z0-9_-]*)?)\s*=\s*("(?:[^"\\\\]|\\\\.)*"|[^\s"();]+)~';
        while ($offset < strlen($value) && trim(substr($value, $offset)) !== '') {
            if (preg_match($pattern, $value, $matches, 0, $offset) !== 1) {
                return null;
            }
            $key = preg_replace('/\s+/', '', $matches[1]);
            if (isset($properties[$key])) {
                return null;
            }
            $propertyValue = $matches[2];
            if ($propertyValue[0] === '"') {
                $propertyValue = preg_replace('/\\\\(.)/s', '$1', substr($propertyValue, 1, -1));
            }
            $properties[$key] = $propertyValue;
            $offset += strlen($matches[0]);
        }

        return $properties;
    }

    private function domainAligns(string $authenticatedDomain, string $expectedDomain): bool
    {
        $authenticatedDomain = rtrim(strtolower($authenticatedDomain), '.');
        $expectedDomain = rtrim(strtolower($expectedDomain), '.');

        return $authenticatedDomain === $expectedDomain
            || str_ends_with($authenticatedDomain, '.' . $expectedDomain);
    }
}
