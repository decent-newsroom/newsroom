<?php

declare(strict_types=1);

namespace DecentNewsroom\ExpressionBundle\Contract;

interface RelaySelectorInterface
{
    /** @return string[] */
    public function getDefaultRelays(): array;

    /** @return string[] */
    public function getContentRelays(): array;

    /** @return string[] */
    public function getAuthorRelays(string $pubkey): array;

    /** @param string[] $relayUrls @return string[] */
    public function ensureLocalRelay(array $relayUrls): array;

    public function canonicalize(string $relayUrl): string;
}
