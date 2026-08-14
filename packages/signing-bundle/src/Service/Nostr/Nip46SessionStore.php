<?php

declare(strict_types=1);

namespace DecentNewsroom\SigningBundle\Service\Nostr;

use DecentNewsroom\SigningBundle\Contract\RemoteSignerSessionStoreInterface;
use DecentNewsroom\SigningBundle\Dto\RemoteSignerSession;

final readonly class Nip46SessionStore
{
    public const TTL_SECONDS = 28800;

    public function __construct(
        private RemoteSignerSessionStoreInterface $store,
        private int $ttlSeconds = self::TTL_SECONDS,
    ) {
    }

    /**
     * @param list<string> $bunkerRelays
     */
    public function store(
        string $subjectId,
        string $clientPrivkeyHex,
        string $bunkerPubkeyHex,
        array $bunkerRelays,
    ): void {
        $this->storeSession(
            $subjectId,
            RemoteSignerSession::forBunker($clientPrivkeyHex, $bunkerPubkeyHex, $bunkerRelays, $subjectId),
        );
    }

    public function storeSession(string $subjectId, RemoteSignerSession $session): void
    {
        $this->store->store($subjectId, $session);
    }

    public function has(string $subjectId): bool
    {
        return $this->store->has($subjectId);
    }

    /**
     * @return array{clientPrivkeyHex: string, bunkerPubkeyHex: string, bunkerRelays: list<string>}|null
     */
    public function get(string $subjectId): ?array
    {
        return $this->getSession($subjectId)?->toLegacyArray();
    }

    public function getSession(string $subjectId): ?RemoteSignerSession
    {
        return $this->store->get($subjectId);
    }

    public function refresh(string $subjectId, int $ttlSeconds = self::TTL_SECONDS): bool
    {
        return $this->store->refresh($subjectId, $ttlSeconds === self::TTL_SECONDS ? $this->ttlSeconds : $ttlSeconds);
    }

    public function remove(string $subjectId): void
    {
        $this->store->remove($subjectId);
    }
}
