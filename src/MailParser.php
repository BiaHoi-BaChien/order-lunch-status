<?php

declare(strict_types=1);

final class MailParser
{
    /**
     * @var list<array{key:string,mail_labels:list<string>}>
     */
    private array $mappedFields;

    /**
     * @param array<string, mixed> $settings
     */
    public function __construct(array $settings = [])
    {
        $this->mappedFields = $this->fieldMappings($settings['mapped_fields'] ?? null);
    }

    /**
     * @return array{date:string,ticket_no:string,item_name:string,size:string,note:string,warn_previous_year:bool,mapped_fields:array<string, string>}
     */
    public function parseOrderConfirmation(array $message): array
    {
        $text = $this->extractText($message);
        $receivedAt = $this->receivedAt($message);

        if (preg_match('/\[(\d{4}-\d{1,2}-\d{1,2})\]/u', $text, $date) !== 1) {
            throw new RuntimeException('注文日付を抽出できません');
        }
        $dateAnswer = $date[1];

        $ticketNo = trim((string) $this->answerFor($text, ['お弁当券ナンバー']));
        if ($ticketNo === '') {
            throw new RuntimeException('お弁当ナンバーを抽出できません');
        }

        $itemName = $this->normalizeMenuText((string) $this->answerFor($text, ['メニュー']));
        if ($itemName === '') {
            throw new RuntimeException('品名を抽出できません');
        }

        $sizeAnswer = (string) $this->answerFor($text, ['サイズ']);
        if (preg_match('/^\s*([SML])\b/iu', $sizeAnswer, $sizeMatch) !== 1) {
            throw new RuntimeException('サイズを抽出できません');
        }
        $size = strtoupper($sizeMatch[1]);

        $note = $this->answerFor($text, ['その他の要望']) ?? '';
        $customization = $this->answerFor($text, ['カスタマイズ']);
        if ($customization !== null && trim($customization) !== '') {
            $note = $this->appendNote($note, 'カスタマイズ: ' . $customization);
        }

        return [
            'date' => $this->parseJapaneseDate($dateAnswer, $receivedAt),
            'ticket_no' => $ticketNo,
            'item_name' => $itemName,
            'size' => $size,
            'note' => $note,
            'warn_previous_year' => $this->isPreviousYearWarning($dateAnswer, $receivedAt),
            'mapped_fields' => $this->extractMappedFields($text),
        ];
    }

    /**
     * @return array{date:string,item_name:string,note:string}
     */
    public function parseKimuraOrderConfirmation(array $message): array
    {
        $text = $this->extractText($message);
        $dateAnswer = $this->answerFor($text, ['注文日']);
        if ($dateAnswer === null || preg_match('/(\d{4})年(\d{1,2})月(\d{1,2})日/u', mb_convert_kana($dateAnswer, 'n', 'UTF-8'), $date) !== 1) {
            throw new RuntimeException('RAMEN KIMURAの注文日を抽出できません');
        }
        if (!checkdate((int) $date[2], (int) $date[3], (int) $date[1])) {
            throw new RuntimeException("RAMEN KIMURAの注文日が実在しません: {$dateAnswer}");
        }

        $menu = $this->answerFor($text, ['メニュー']);
        $itemName = trim((string) preg_replace([
            '/^\d{4}年\d{1,2}月\d{1,2}日\s*メニュー[:：]\s*/u',
            '/\s+\d[\d,]*\s*VND\s*$/iu',
        ], '', (string) $menu));
        if ($itemName === '') {
            throw new RuntimeException('RAMEN KIMURAのメニューを抽出できません');
        }

        $amount = trim((string) $this->answerFor($text, ['合計金額']));
        if ($amount === '') {
            throw new RuntimeException('RAMEN KIMURAの合計金額を抽出できません');
        }

        return [
            'date' => sprintf('%04d-%02d-%02d', (int) $date[1], (int) $date[2], (int) $date[3]),
            'item_name' => $itemName,
            'note' => '合計金額: ' . $amount,
        ];
    }

    /**
     * @return array{date:string,warn_previous_year:bool}
     */
    public function parseReceipt(array $message): array
    {
        $text = $this->extractText($message);
        $dateText = $this->extractReceiptDateText($text);
        if ($dateText === null) {
            throw new RuntimeException('受付メールの日付を抽出できません');
        }

        $receivedAt = $this->receivedAt($message);
        $date = $this->parseJapaneseDate($dateText, $receivedAt);

        return [
            'date' => $date,
            'warn_previous_year' => $this->isPreviousYearWarning($dateText, $receivedAt),
        ];
    }

    /**
     * @return array{date:string,warn_previous_year:bool}
     */
    public function parseKimuraReceipt(array $message): array
    {
        $text = mb_convert_kana($this->extractText($message), 'n', 'UTF-8');
        if (preg_match('/お届け日\s*[:：]\s*(\d{4})\s*\/\s*(\d{1,2})\s*\/\s*(\d{1,2})/u', $text, $date) !== 1) {
            throw new RuntimeException('RAMEN KIMURA受付メールのお届け日を抽出できません');
        }
        if (!checkdate((int) $date[2], (int) $date[3], (int) $date[1])) {
            throw new RuntimeException("RAMEN KIMURA受付メールのお届け日が実在しません: {$date[0]}");
        }

        return [
            'date' => sprintf('%04d-%02d-%02d', (int) $date[1], (int) $date[2], (int) $date[3]),
            'warn_previous_year' => false,
        ];
    }

    public function extractText(array $message): string
    {
        $payload = $message['payload'] ?? null;
        if (!is_array($payload)) {
            throw new RuntimeException('メール本文のデコード失敗: payloadがありません');
        }

        $plain = [];
        $html = [];
        $this->collectParts($payload, $plain, $html);

        if ($html !== []) {
            $rawHtml = implode("\n", $html);
            $rawHtml = preg_replace('/<img\b[^>]*>/iu', '', $rawHtml) ?? $rawHtml;
            $htmlText = preg_replace('/<(br|\/p|\/div|\/tr|\/li|\/h[1-6])\b[^>]*>/iu', "\n", $rawHtml) ?? $rawHtml;
            $plain[] = html_entity_decode(strip_tags($htmlText), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        if ($plain !== []) {
            return $this->normalizeText(implode("\n", $plain));
        }

        throw new RuntimeException('メール本文のデコード失敗: text/plainまたはtext/htmlがありません');
    }

    public function parseJapaneseDate(string $value, DateTimeImmutable $receivedAt): string
    {
        $normalizedValue = mb_convert_kana($value, 'a', 'UTF-8');
        if (preg_match('/(\d{4})-(\d{1,2})-(\d{1,2})/u', $normalizedValue, $date) === 1) {
            if (!checkdate((int) $date[2], (int) $date[3], (int) $date[1])) {
                throw new RuntimeException("実在しない日付です: {$date[0]}");
            }

            return sprintf('%04d-%02d-%02d', (int) $date[1], (int) $date[2], (int) $date[3]);
        }
        if (!preg_match('/(\d{1,2})月(\d{1,2})日/u', $normalizedValue, $m)) {
            throw new RuntimeException('注文日付を抽出できません');
        }

        $mailMonth = (int) $receivedAt->format('n');
        $orderMonth = (int) $m[1];
        $year = (int) $receivedAt->format('Y');

        if ($mailMonth === 12 && $orderMonth === 1) {
            $year++;
        } elseif ($mailMonth === 1 && $orderMonth === 12) {
            $year--;
        }

        $day = (int) $m[2];
        if (!checkdate($orderMonth, $day, $year)) {
            throw new RuntimeException(sprintf('実在しない日付です: %04d-%02d-%02d', $year, $orderMonth, $day));
        }

        return sprintf('%04d-%02d-%02d', $year, $orderMonth, $day);
    }

    private function extractReceiptDateText(string $text): ?string
    {
        $patterns = [
            '/(\d{1,2})月(\d{1,2})日(?:[（(][月火水木金土日][）)])?[^\n。]*注文を受け付けました/u',
            '/(\d{1,2})月(\d{1,2})日(?:[（(][月火水木金土日][）)])?[^\n。]*注文受付/u',
            '/(\d{1,2})月(\d{1,2})日(?:[（(][月火水木金土日][）)])?[^\n。]*お弁当/u',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $m)) {
                return $m[1] . '月' . $m[2] . '日';
            }
        }

        if (preg_match_all('/(\d{1,2})月(\d{1,2})日(?:[（(][月火水木金土日][）)])?/u', $text, $matches, PREG_SET_ORDER) !== false) {
            foreach ($matches as $match) {
                if ((int) $match[2] >= 1) {
                    return $match[1] . '月' . $match[2] . '日';
                }
            }
        }

        return null;
    }
    private function receivedAt(array $message): DateTimeImmutable
    {
        if (!empty($message['internalDate'])) {
            return (new DateTimeImmutable('@' . intdiv((int) $message['internalDate'], 1000)))->setTimezone(new DateTimeZone(date_default_timezone_get()));
        }

        foreach (($message['payload']['headers'] ?? []) as $header) {
            if (strcasecmp((string) ($header['name'] ?? ''), 'Date') === 0 && !empty($header['value'])) {
                return new DateTimeImmutable((string) $header['value']);
            }
        }

        return new DateTimeImmutable('now');
    }

    private function collectParts(array $part, array &$plain, array &$html): void
    {
        $mimeType = strtolower((string) ($part['mimeType'] ?? ''));
        $data = $part['body']['data'] ?? null;

        if (is_string($data) && ($mimeType === 'text/plain' || $mimeType === 'text/html')) {
            $decoded = $this->decodeBody($data);
            if ($mimeType === 'text/plain') {
                $plain[] = $decoded;
            } else {
                $html[] = $decoded;
            }
        }

        foreach (($part['parts'] ?? []) as $child) {
            if (is_array($child)) {
                $this->collectParts($child, $plain, $html);
            }
        }
    }

    private function decodeBody(string $data): string
    {
        $normalized = strtr($data, '-_', '+/');
        $padding = strlen($normalized) % 4;
        if ($padding > 0) {
            $normalized .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode($normalized, true);
        if ($decoded === false) {
            throw new RuntimeException('メール本文のデコード失敗: base64urlを復号できません');
        }

        $decoded = quoted_printable_decode($decoded);
        if (!mb_check_encoding($decoded, 'UTF-8')) {
            $converted = mb_convert_encoding($decoded, 'UTF-8', 'ISO-2022-JP,SJIS-win,EUC-JP,UTF-8');
            if ($converted !== false) {
                $decoded = $converted;
            }
        }

        return $decoded;
    }

    private function normalizeText(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[ \t　]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;

        return trim($text);
    }

    private function answerFor(string $text, array $labels): ?string
    {
        foreach (array_map('trim', explode("\n", $text)) as $line) {
            foreach ($labels as $label) {
                if (str_contains($line, $label) && preg_match('/[:：]\s*(.+)$/u', $line, $m)) {
                    return trim($m[1]);
                }
            }
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    private function extractMappedFields(string $text): array
    {
        $fields = [];
        foreach ($this->mappedFields as $mapping) {
            $answer = $this->answerFor($text, $mapping['mail_labels']);
            if ($answer === null) {
                continue;
            }

            $value = $this->normalizeText($answer);
            if ($value !== '') {
                $fields[$mapping['key']] = $value;
            }
        }

        return $fields;
    }

    private function appendNote(string $note, string $addition): string
    {
        $note = $this->normalizeText($note);
        $addition = $this->normalizeText($addition);
        if ($addition === '') {
            return $note;
        }
        if ($note === '') {
            return $addition;
        }

        return $note . '、' . $addition;
    }

    private function normalizeMenuText(string $value): string
    {
        $value = strtr($value, ['Ａ' => 'A', 'Ｂ' => 'B']);
        $value = preg_replace('/\s+（/u', '（', $value) ?? $value;

        return $this->normalizeText($value);
    }

    private function isPreviousYearWarning(string $value, DateTimeImmutable $receivedAt): bool
    {
        $normalizedValue = mb_convert_kana($value, 'a', 'UTF-8');
        if (!preg_match('/(\d{1,2})月/u', $normalizedValue, $m)) {
            return false;
        }

        return (int) $receivedAt->format('n') === 1 && (int) $m[1] === 12;
    }

    /**
     * @param mixed $value
     * @param list<string> $default
     * @return list<string>
     */
    private function stringList(mixed $value, array $default): array
    {
        if (!is_array($value)) {
            return $default;
        }

        $items = array_values(array_filter(
            array_map(
                static fn (mixed $item): string => is_scalar($item) ? trim((string) $item) : '',
                $value
            ),
            static fn (string $item): bool => $item !== ''
        ));

        return $items === [] ? $default : $items;
    }

    /**
     * @param mixed $value
     * @return list<array{key:string,mail_labels:list<string>}>
     */
    private function fieldMappings(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $mappings = [];
        foreach ($value as $mapping) {
            if (!is_array($mapping)) {
                continue;
            }

            $key = trim((string) ($mapping['key'] ?? ''));
            $labels = $this->stringList($mapping['mail_labels'] ?? null, []);
            if ($key !== '' && $labels !== []) {
                $mappings[] = [
                    'key' => $key,
                    'mail_labels' => $labels,
                ];
            }
        }

        return $mappings;
    }
}
