<?php

declare(strict_types=1);

final class BatchRunLock
{
    /** @var resource|null */
    private $handle;

    /** @param resource $handle */
    private function __construct($handle)
    {
        $this->handle = $handle;
    }

    public static function acquire(string $path): ?self
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new RuntimeException("ロックディレクトリを作成できません: {$dir}");
        }

        $handle = fopen($path, 'c');
        if ($handle === false) {
            throw new RuntimeException("バッチ実行ロックを開けません: {$path}");
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return null;
        }

        return new self($handle);
    }

    public function release(): void
    {
        if (is_resource($this->handle)) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
            $this->handle = null;
        }
    }

    public function __destruct()
    {
        $this->release();
    }
}
