<?php

declare(strict_types=1);

if (!class_exists('Redis')) {
    class Redis
    {
        public function set(string $key, mixed $value, mixed $options = null): bool
        {
            return true;
        }

        public function get(string $key): mixed
        {
            return false;
        }

        public function exists(string|array $key): int
        {
            return 0;
        }

        public function del(string|array $key, string ...$otherKeys): int
        {
            return 0;
        }

        public function expire(string $key, int $seconds, ?string $mode = null): bool
        {
            return true;
        }
    }
}

if (!class_exists('RedisException')) {
    class RedisException extends RuntimeException
    {
    }
}
