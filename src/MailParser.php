<?php

declare(strict_types=1);

final class MailParser
{
    /**
     * @return array{date:string,ticket_no:string,item_name:string,size:string,note:string,warn_previous_year:bool}
     */
    public function parseOrderConfirmation(array $message): array
    {
        $text = $this->extractText($message);
        $htmlAnswers = $this->extractGoogleFormAnswersFromHtml($message);
        $receivedAt = $this->receivedAt($message);

        $dateLabels = [
            'お子様がお弁当を召し上がる日付を記載してください',
            'お弁当を召し上がる日付',
        ];
        $dateAnswer = $this->answerFromMap($htmlAnswers, $dateLabels) ?? $this->answerFor($text, $dateLabels);
        if ($dateAnswer === null && preg_match('/\d{1,2}月\d{1,2}日(?:[（(][月火水木金土日][）)])?/u', $text, $m)) {
            $dateAnswer = $m[0];
        }
        if ($dateAnswer === null) {
            throw new RuntimeException('注文日付を抽出できません');
        }

        $ticketLabels = [
            'お手持ちのお弁当券に記載してある数字4ケタのお弁当ナンバー',
            'お弁当ナンバー',
            'お弁当番号',
        ];
        $ticketAnswer = $this->answerFromMap($htmlAnswers, $ticketLabels) ?? $this->answerFor($text, $ticketLabels);
        $ticketNo = trim((string) $ticketAnswer);
        if ($ticketNo === '') {
            throw new RuntimeException('お弁当ナンバーを抽出できません');
        }

        $itemName = $this->extractItemName($text, $htmlAnswers);
        if ($itemName === null) {
            throw new RuntimeException('品名を抽出できません');
        }

        $sizeLabels = ['ライスの量', 'ご飯の量', 'サイズ'];
        $sizeAnswer = $this->answerFromMap($htmlAnswers, $sizeLabels) ?? $this->answerFor($text, $sizeLabels);
        $sizeSource = $sizeAnswer ?? $text;
        if (!preg_match('/^\s*([SML])\b|([SML])\s*(?:ライス)?\s*\d{3}\s*[gｇ]/iu', $sizeSource, $sizeMatch)) {
            throw new RuntimeException('サイズを抽出できません');
        }
        $size = strtoupper(($sizeMatch[1] ?? '') !== '' ? $sizeMatch[1] : $sizeMatch[2]);

        $noteLabels = ['備考', 'ご要望'];
        $note = $this->answerFromMap($htmlAnswers, $noteLabels) ?? ($htmlAnswers !== [] ? '' : ($this->answerFor($text, $noteLabels) ?? ''));

        return [
            'date' => $this->parseJapaneseDate($dateAnswer, $receivedAt),
            'ticket_no' => $ticketNo,
            'item_name' => $itemName,
            'size' => $size,
            'note' => $note,
            'warn_previous_year' => $this->isPreviousYearWarning($dateAnswer, $receivedAt),
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

    public function extractText(array $message): string
    {
        $payload = $message['payload'] ?? null;
        if (!is_array($payload)) {
            throw new RuntimeException('メール本文のデコード失敗: payloadがありません');
        }

        $plain = [];
        $html = [];
        $this->collectParts($payload, $plain, $html);

        if ($plain !== []) {
            return $this->normalizeText(implode("\n", $plain));
        }
        if ($html !== []) {
            $rawHtml = implode("\n", $html);
            $rawHtml = preg_replace('/<img\b[^>]*>/iu', '', $rawHtml) ?? $rawHtml;
            $htmlText = preg_replace('/<(br|\/p|\/div|\/tr|\/li|\/h[1-6])\b[^>]*>/iu', "\n", $rawHtml) ?? $rawHtml;
            return $this->normalizeText(html_entity_decode(strip_tags($htmlText), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        throw new RuntimeException('メール本文のデコード失敗: text/plainまたはtext/htmlがありません');
    }

    /**
     * @return array<string, string>
     */
    public function extractGoogleFormAnswersFromHtml(array $message): array
    {
        $payload = $message['payload'] ?? null;
        if (!is_array($payload)) {
            return [];
        }

        $plain = [];
        $htmlParts = [];
        $this->collectParts($payload, $plain, $htmlParts);
        if ($htmlParts === []) {
            return [];
        }

        $answers = [];
        foreach ($htmlParts as $html) {
            foreach ($this->parseGoogleFormHtmlAnswers($html) as $question => $answer) {
                $answers[$question] = $answer;
            }
        }

        return $answers;
    }

    /**
     * @return array<string, string>
     */
    private function parseGoogleFormHtmlAnswers(string $html): array
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $answers = [];
        foreach ($document->getElementsByTagName('h2') as $heading) {
            $question = $this->normalizeText($heading->textContent);
            if ($question === '') {
                continue;
            }

            $answer = $this->findAnswerAfterHeading($heading);
            if ($answer !== null) {
                $answers[$question] = $answer;
            }
        }

        return $answers;
    }

    private function findAnswerAfterHeading(DOMNode $heading): ?string
    {
        $cursor = $heading;
        while ($cursor->parentNode !== null) {
            $node = $cursor->nextSibling;
            while ($node !== null) {
                if ($node instanceof DOMElement) {
                    if (strtolower($node->tagName) === 'h2') {
                        return null;
                    }

                    $answer = $this->firstAnswerTextInNode($node);
                    if ($answer !== null) {
                        return $answer;
                    }
                }
                $node = $node->nextSibling;
            }

            $cursor = $cursor->parentNode;
        }

        return null;
    }

    private function firstAnswerTextInNode(DOMElement $node): ?string
    {
        if (strtolower($node->tagName) === 'img') {
            return null;
        }


        if (strtolower($node->tagName) === 'div') {
            $style = (string) $node->getAttribute('style');
            if (str_contains($style, 'border-bottom')) {
                $text = $this->normalizeText($node->textContent);
                return $this->isSkippableAnswerLine($text) ? null : $text;
            }
            if (str_contains($style, 'border: 1px solid #dadce0') && $node->getElementsByTagName('h2')->length === 0) {
                $text = $this->normalizeText($node->textContent);
                return $this->isSkippableAnswerLine($text) ? null : $text;
            }
            if ($node->getElementsByTagName('h2')->length === 0) {
                $checkedBoxes = $this->selectedCheckboxTextInNode($node);
                if ($checkedBoxes !== null) {
                    return $checkedBoxes;
                }
            }
        }

        if ($this->isCheckedControl($node)) {
            $checked = $this->controlLabel($node);
            return $checked === null ? null : $this->normalizeMenuText($checked);
        }

        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $answer = $this->firstAnswerTextInNode($child);
                if ($answer !== null) {
                    return $answer;
                }
            }
        }

        return null;
    }
    private function selectedCheckboxTextInNode(DOMElement $node): ?string
    {
        $selected = [];
        foreach ($node->getElementsByTagName('div') as $div) {
            if (strtolower((string) $div->getAttribute('role')) === 'checkbox'
                && strtolower((string) $div->getAttribute('aria-checked')) === 'true') {
                $selected[] = $this->controlLabel($div);
            }
        }

        $selected = array_values(array_filter(array_map(
            fn (?string $value): ?string => $value === null ? null : $this->normalizeMenuText($this->normalizeText($value)),
            $selected
        ), fn (?string $value): bool => $value !== null && $value !== ''));

        return $selected === [] ? null : implode('、', array_unique($selected));
    }

    private function selectedControlTextInNode(DOMElement $node): ?string
    {
        $selected = [];
        if ($this->isCheckedControl($node)) {
            $selected[] = $this->controlLabel($node);
        }

        foreach ($node->getElementsByTagName('div') as $div) {
            if ($this->isCheckedControl($div)) {
                $selected[] = $this->controlLabel($div);
            }
        }

        $selected = array_values(array_filter(array_map(
            fn (?string $value): ?string => $value === null ? null : $this->normalizeMenuText($this->normalizeText($value)),
            $selected
        ), fn (?string $value): bool => $value !== null && $value !== ''));

        return $selected === [] ? null : implode('、', array_unique($selected));
    }

    private function isCheckedControl(DOMElement $node): bool
    {
        $role = strtolower((string) $node->getAttribute('role'));

        return in_array($role, ['radio', 'checkbox'], true)
            && strtolower((string) $node->getAttribute('aria-checked')) === 'true';
    }

    private function controlLabel(DOMElement $node): ?string
    {
        $label = $this->normalizeText((string) $node->getAttribute('aria-label'));
        if ($label !== '') {
            return $label;
        }

        $row = $node;
        while ($row->parentNode instanceof DOMElement && strtolower($row->tagName) !== 'tr') {
            $row = $row->parentNode;
        }

        return $this->normalizeText($row->textContent);
    }

    public function parseJapaneseDate(string $value, DateTimeImmutable $receivedAt): string
    {
        $normalizedValue = mb_convert_kana($value, 'a', 'UTF-8');
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

    /**
     * @param array<int, string> $labels
     */
    private function answerFromMap(array $answers, array $labels): ?string
    {
        foreach ($answers as $question => $answer) {
            foreach ($labels as $label) {
                if (str_contains($question, $label) && !$this->isSkippableAnswerLine($answer)) {
                    return $answer;
                }
            }
        }

        return null;
    }

    private function answerFor(string $text, array $labels): ?string
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", $text)), static fn (string $line): bool => $line !== ''));
        foreach ($lines as $i => $line) {
            foreach ($labels as $label) {
                if (!str_contains($line, $label)) {
                    continue;
                }

                if (preg_match('/[:：]\s*(.+)$/u', $line, $m)) {
                    return trim($m[1]);
                }

                for ($j = $i + 1; $j < min(count($lines), $i + 10); $j++) {
                    if ($this->isSkippableAnswerLine($lines[$j])) {
                        continue;
                    }

                    return trim($lines[$j]);
                }
            }
        }

        return null;
    }

    private function extractItemName(string $text, array $htmlAnswers): ?string
    {
        $labels = ['品名', '注文したお弁当', 'お弁当の種類', 'メニュー', 'アレルギー物質'];
        $answer = $this->answerFromMap($htmlAnswers, $labels) ?? $this->answerFor($text, $labels);
        if ($answer !== null && !preg_match('/^(S|M|L)\s*\d{3}\s*[gｇ]?$/iu', $answer)) {
            $knownAnswer = $this->knownItemName($answer);
            return $knownAnswer ?? $this->normalizeMenuText($answer);
        }

        foreach ($htmlAnswers as $answerValue) {
            $knownAnswer = $this->knownItemName($answerValue);
            if ($knownAnswer !== null) {
                return $knownAnswer;
            }
        }

        $knownAnswer = $this->knownItemName($text);
        return $knownAnswer;
    }

    private function knownItemName(string $value): ?string
    {
        $normalizedValue = $this->normalizeMenuText($value);
        $knownItems = [
            '牛めし（A券：牛めし）',
            'キムチ牛めし（B券：定食・丼）',
            '唐揚げ定食（B券：定食・丼）',
            'ふわ玉あんかけ牛めし（B券：定食・丼）',
            'ふわとろあんかけ牛めし（B券：定食・丼）',
        ];

        foreach ($knownItems as $item) {
            if (str_contains($normalizedValue, $item)) {
                return $item;
            }
        }

        return null;
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

    private function isSkippableAnswerLine(string $line): bool
    {
        $line = trim($line);
        if ($line === '' || $line === '説明のない画像' || preg_match('/^\\*+$/u', $line)) {
            return true;
        }
        if ($this->knownItemName($line) !== null || preg_match('/^\s*[SML]\b|[SML]\s*(?:ライス)?\s*\d{3}\s*[gｇ]/iu', $line)) {
            return false;
        }

        if ($this->looksLikeQuestion($line) || str_contains($line, '必須')) {
            return true;
        }

        return preg_match('/^(?:い|い。|さい|さい。|ださい|ださい。|ください|ください。)\\s*\\*?$/u', $line) === 1;
    }

    private function looksLikeQuestion(string $line): bool
    {
        return str_contains($line, 'してください')
            || str_contains($line, 'お弁当')
            || str_contains($line, 'ライス')
            || str_contains($line, '備考')
            || str_contains($line, '品名');
    }
}
