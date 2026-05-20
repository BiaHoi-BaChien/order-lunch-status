<?php

declare(strict_types=1);

final class RunWindow
{
    public static function isAllowed(DateTimeImmutable $now, bool $enabled, int $startHour, int $endHour): bool
    {
        if (!$enabled) {
            return true;
        }

        self::assertHour($startHour, 'RUN_WINDOW_START_HOUR');
        self::assertHour($endHour, 'RUN_WINDOW_END_HOUR');

        if ($startHour > $endHour) {
            throw new RuntimeException('RUN_WINDOW_START_HOUR は RUN_WINDOW_END_HOUR 以下にしてください');
        }

        $hour = (int) $now->format('G');

        return $startHour <= $hour && $hour <= $endHour;
    }

    private static function assertHour(int $hour, string $name): void
    {
        if ($hour < 0 || $hour > 23) {
            throw new RuntimeException("{$name} は 0 から 23 の整数で指定してください");
        }
    }
}
