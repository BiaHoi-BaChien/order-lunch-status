<?php

declare(strict_types=1);

final class NotionPropertyPayloadBuilder
{
    /**
     * @param list<array{key:string,notion_property:string,notion_type:string}> $mappings
     * @param array<string, string> $fields
     * @return array<string, array<string, mixed>>
     */
    public function build(array $mappings, array $fields): array
    {
        $properties = [];
        foreach ($mappings as $mapping) {
            $key = (string) ($mapping['key'] ?? '');
            $property = trim((string) ($mapping['notion_property'] ?? ''));
            $type = strtolower(trim((string) ($mapping['notion_type'] ?? 'rich_text')));
            $value = trim((string) ($fields[$key] ?? ''));
            if ($property === '' || $value === '') {
                continue;
            }

            $properties[$property] = $this->propertyPayload($type, $value);
        }

        return $properties;
    }

    /**
     * @return array<string, mixed>
     */
    private function propertyPayload(string $type, string $value): array
    {
        return match ($type) {
            'title' => ['title' => [['text' => ['content' => $value]]]],
            'select' => ['select' => ['name' => $value]],
            'url' => ['url' => $value],
            'number' => ['number' => $this->numberValue($value)],
            'checkbox' => ['checkbox' => $this->checkboxValue($value)],
            default => ['rich_text' => [['text' => ['content' => $value]]]],
        };
    }

    private function numberValue(string $value): int|float
    {
        $normalized = str_replace(',', '', mb_convert_kana($value, 'n', 'UTF-8'));
        if (!is_numeric($normalized)) {
            throw new RuntimeException("Notion number propertyに変換できません: {$value}");
        }

        $number = (float) $normalized;
        return floor($number) === $number ? (int) $number : $number;
    }

    private function checkboxValue(string $value): bool
    {
        return in_array(strtolower($value), ['1', 'true', 'yes', 'on', 'checked', 'はい', 'あり'], true);
    }
}
