<?php

declare(strict_types=1);

namespace DecentNewsroom\SigningBundle\Service\Nostr;

final class RelayAuthEventFactory
{
    /** @return array<string, mixed> */
    public function create(string $pubkeyHex, string $relayUrl, string $challenge): array
    {
        return [
            'kind' => 22242,
            'pubkey' => $pubkeyHex,
            'created_at' => time(),
            'tags' => [
                ['relay', $relayUrl],
                ['challenge', $challenge],
            ],
            'content' => '',
        ];
    }
}
