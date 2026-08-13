<?php

declare(strict_types=1);

namespace App\Service\Nostr;

use DecentNewsroom\NostrKernelBundle\Contract\Nip19\Nip19DecoderInterface;
use DecentNewsroom\NostrKernelBundle\Contract\Nip19\Nip19EncoderInterface;
use DecentNewsroom\NostrKernelBundle\Domain\Identity\Pubkey;
use DecentNewsroom\NostrKernelBundle\Domain\Nip19\DecodedNip19;
use DecentNewsroom\NostrKernelBundle\Domain\Nip19\Nip19Type;

final readonly class NostrIdentityService
{
    public function __construct(
        private Nip19DecoderInterface $decoder,
        private Nip19EncoderInterface $encoder,
    ) {
    }

    public function decode(string $identifier): DecodedNip19
    {
        return $this->decoder->decode($this->normalizeIdentifier($identifier));
    }

    public function toHex(string $identifier): string
    {
        $normalized = $this->normalizeIdentifier($identifier);
        if (\preg_match('/^[a-f0-9]{64}$/', $normalized)) {
            return $normalized;
        }

        if (!\preg_match('/^(npub|nprofile)1[a-z0-9]+$/', $normalized)) {
            throw new \InvalidArgumentException('Identifier does not contain a valid public key.');
        }

        $decoded = $this->decoder->decode($normalized);
        if (!\in_array($decoded->type(), [Nip19Type::NPUB, Nip19Type::NPROFILE], true)) {
            throw new \InvalidArgumentException('Identifier does not contain a public key.');
        }

        $pubkey = $decoded->data()['pubkey'] ?? null;
        if (!\is_string($pubkey)) {
            throw new \InvalidArgumentException('Identifier does not contain a valid public key.');
        }

        return (new Pubkey(\strtolower($pubkey)))->toHex();
    }

    /**
     * @param list<string> $relays
     */
    public function encodeAddressable(
        string $identifier,
        string $pubkey,
        int $kind,
        array $relays = [],
    ): string {
        $decoded = new DecodedNip19(
            Nip19Type::NADDR,
            [
                'identifier' => $identifier,
                'pubkey' => (new Pubkey(\strtolower($pubkey)))->toHex(),
                'kind' => $kind,
                'relays' => $relays,
            ],
        );

        return $this->encoder->encode($decoded);
    }

    /**
     * Encode a raw hex pubkey into its npub (bech32) representation.
     */
    public function encodeNpub(string $hexPubkey): string
    {
        $decoded = new DecodedNip19(
            Nip19Type::NPUB,
            ['pubkey' => (new Pubkey(\strtolower($hexPubkey)))->toHex()],
        );

        return $this->encoder->encode($decoded);
    }

    private function normalizeIdentifier(string $identifier): string
    {
        $identifier = \strtolower(\trim($identifier));
        if (\str_starts_with($identifier, 'nostr:')) {
            $identifier = \substr($identifier, 6);
        }

        if ($identifier === '') {
            throw new \InvalidArgumentException('Nostr identifier cannot be empty.');
        }

        return $identifier;
    }
}
