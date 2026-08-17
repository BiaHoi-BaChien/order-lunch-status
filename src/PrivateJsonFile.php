<?php

declare(strict_types=1);

final class PrivateJsonFile
{
    public static function write(string $path, string $json): void
    {
        if (is_link($path)) {
            throw new RuntimeException("秘密情報ファイルとしてシンボリックリンクは使用できません: {$path}");
        }

        $dir = dirname($path);
        if (!is_dir($dir)) {
            $oldUmask = umask(0077);
            try {
                $created = mkdir($dir, 0700, true);
            } finally {
                umask($oldUmask);
            }
            if (!$created && !is_dir($dir)) {
                throw new RuntimeException("秘密情報ディレクトリを作成できません: {$dir}");
            }
        }

        $oldUmask = umask(0077);
        try {
            $handle = fopen($path, 'c+b');
        } finally {
            umask($oldUmask);
        }
        if ($handle === false) {
            throw new RuntimeException("秘密情報ファイルを開けません: {$path}");
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException("秘密情報ファイルをロックできません: {$path}");
            }
            if (!chmod($path, 0600)) {
                throw new RuntimeException("秘密情報ファイルの権限を0600に設定できません: {$path}");
            }
            if (!ftruncate($handle, 0) || !rewind($handle)) {
                throw new RuntimeException("秘密情報ファイルを更新できません: {$path}");
            }

            $contents = $json . PHP_EOL;
            $written = 0;
            while ($written < strlen($contents)) {
                $length = fwrite($handle, substr($contents, $written));
                if ($length === false || $length === 0) {
                    throw new RuntimeException("秘密情報ファイルを書き込めません: {$path}");
                }
                $written += $length;
            }
            if (!fflush($handle)) {
                throw new RuntimeException("秘密情報ファイルを同期できません: {$path}");
            }
            if (function_exists('fsync') && !fsync($handle)) {
                throw new RuntimeException("秘密情報ファイルを永続化できません: {$path}");
            }
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
