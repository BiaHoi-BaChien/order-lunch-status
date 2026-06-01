<?php

declare(strict_types=1);

final class EnvFileEditor
{
    /**
     * @return array<string, string>
     */
    public static function readValues(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new RuntimeException(".env を読み込めません: {$path}");
        }

        $values = [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            if ($key === '') {
                continue;
            }

            $values[$key] = self::decodeValue(trim($value));
        }

        return $values;
    }

    /**
     * @param array<string, string> $updates
     */
    public static function updateValues(string $path, array $updates): void
    {
        foreach ($updates as $key => $value) {
            if (preg_match('/^[A-Z0-9_]+$/', $key) !== 1) {
                throw new InvalidArgumentException("不正なキーです: {$key}");
            }
            if (str_contains($value, "\n") || str_contains($value, "\r")) {
                throw new InvalidArgumentException("{$key} に改行は保存できません");
            }
        }

        $lines = is_file($path) ? file($path, FILE_IGNORE_NEW_LINES) : [];
        if ($lines === false) {
            throw new RuntimeException(".env を読み込めません: {$path}");
        }

        $seen = [];
        foreach ($lines as $i => $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$key] = explode('=', $line, 2);
            $key = trim($key);
            if ($key === '' || !array_key_exists($key, $updates)) {
                continue;
            }

            $lines[$i] = $key . '=' . self::encodeValue($updates[$key]);
            $seen[$key] = true;
        }

        foreach ($updates as $key => $value) {
            if (!isset($seen[$key])) {
                $lines[] = $key . '=' . self::encodeValue($value);
            }
        }

        $content = implode(PHP_EOL, $lines) . PHP_EOL;
        if (file_put_contents($path, $content, LOCK_EX) === false) {
            throw new RuntimeException(".env を書き込めません: {$path}");
        }
    }

    /**
     * @param list<string> $items
     */
    public static function listToEnv(array $items): string
    {
        $items = array_values(array_filter(
            array_map(static fn (string $item): string => trim($item), $items),
            static fn (string $item): bool => $item !== ''
        ));

        return implode('|', $items);
    }

    /**
     * @return list<string>
     */
    public static function envToList(string $value): array
    {
        return array_values(array_filter(
            array_map('trim', explode('|', $value)),
            static fn (string $item): bool => $item !== ''
        ));
    }

    private static function decodeValue(string $value): string
    {
        if (strlen($value) >= 2) {
            $quote = $value[0];
            if (($quote === '"' || $quote === "'") && substr($value, -1) === $quote) {
                return substr($value, 1, -1);
            }
        }

        return $value;
    }

    private static function encodeValue(string $value): string
    {
        if ($value === '') {
            return '';
        }

        if (preg_match('/^\s|\s$/', $value) === 1 || str_contains($value, '#') || str_contains($value, '"')) {
            return '"' . str_replace('"', '\"', $value) . '"';
        }

        return $value;
    }
}
