<?php

declare(strict_types=1);

final class Logger
{
    public function __construct(private readonly string $path)
    {
        $dir = dirname($this->path);
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new RuntimeException("ログディレクトリを作成できません: {$dir}");
        }
    }

    public function info(string $message): void
    {
        $this->write('INFO', $message);
    }

    public function warn(string $message): void
    {
        $this->write('WARN', $message);
    }

    public function error(string $message): void
    {
        $this->write('ERROR', $message);
    }

    private function write(string $level, string $message): void
    {
        $line = sprintf("[%s] %-5s %s\n", (new DateTimeImmutable('now'))->format('Y-m-d H:i:s'), $level, $message);
        file_put_contents($this->path, $line, FILE_APPEND | LOCK_EX);
        fwrite(STDOUT, $line);
    }
}
