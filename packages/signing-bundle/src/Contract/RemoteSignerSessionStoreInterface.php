<?php

declare(strict_types=1);

namespace DecentNewsroom\SigningBundle\Contract;

use DecentNewsroom\SigningBundle\Dto\RemoteSignerSession;

interface RemoteSignerSessionStoreInterface
{
    public function store(string $subjectId, RemoteSignerSession $session): void;

    public function has(string $subjectId): bool;

    public function get(string $subjectId): ?RemoteSignerSession;

    public function refresh(string $subjectId, int $ttlSeconds): bool;

    public function remove(string $subjectId): void;
}
