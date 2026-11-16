<?php

declare(strict_types=1);

namespace App\Tests\Fixtures;

/** Test-only stream wrapper for deterministic aggregate I/O failures. */
final class WorkspaceMetricsFailureStream
{
    public mixed $context;
    public static string $mode = '';
    private int $writes = 0;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return true;
    }

    public function stream_lock(int $operation): bool
    {
        return true;
    }

    public function stream_read(int $count): string
    {
        if (self::$mode === 'read') {
            throw new \RuntimeException('sensitive synthetic read failure');
        }

        return '';
    }

    public function stream_eof(): bool
    {
        return self::$mode !== 'read';
    }

    public function stream_seek(int $offset, int $whence = SEEK_SET): bool
    {
        return true;
    }

    public function stream_tell(): int
    {
        return 0;
    }

    public function stream_truncate(int $size): bool
    {
        return self::$mode !== 'truncate';
    }

    public function stream_write(string $data): int
    {
        if (self::$mode === 'short') {
            return $this->writes++ === 0 ? 1 : 0;
        }

        return strlen($data);
    }

    public function stream_flush(): bool
    {
        return self::$mode !== 'flush';
    }

    public function stream_stat(): array
    {
        return [];
    }
}
