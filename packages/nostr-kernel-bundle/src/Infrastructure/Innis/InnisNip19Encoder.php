<?php

declare(strict_types=1);

namespace DecentNewsroom\NostrKernelBundle\Infrastructure\Innis;

use DecentNewsroom\NostrKernelBundle\Contract\Nip19\Nip19EncoderInterface;
use DecentNewsroom\NostrKernelBundle\Domain\Nip19\DecodedNip19;
use DecentNewsroom\NostrKernelBundle\Domain\Nip19\Nip19Type;
use Innis\Nostr\Core\Domain\Service\Bech32EncoderInterface;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;

final readonly class InnisNip19Encoder implements Nip19EncoderInterface
{
    public function __construct(private Bech32EncoderInterface $encoder)
    {
    }

    public function encode(DecodedNip19 $decoded): string
    {
        return match ($decoded->type()) {
            Nip19Type::NPUB => $this->publicKeyFrom($decoded)->toBech32(),
            Nip19Type::NADDR => $this->encoder->encodeAddressableEvent(
                $this->identifierFrom($decoded),
                $this->publicKeyFrom($decoded),
                $this->kindFrom($decoded),
                $this->relaysFrom($decoded),
            ),
            default => throw new \UnexpectedValueException(sprintf(
                'Unsupported NIP-19 type for encoding: %s.',
                $decoded->type()->value,
            )),
        };
    }

    private function publicKeyFrom(DecodedNip19 $decoded): PublicKey
    {
        $pubkey = $decoded->data()['pubkey'] ?? null;
        if (!\is_string($pubkey)) {
            throw new \UnexpectedValueException('Decoded NIP-19 value is missing a pubkey.');
        }

        return PublicKey::fromHex(\strtolower($pubkey))
            ?? throw new \UnexpectedValueException('Decoded NIP-19 value contains an invalid pubkey.');
    }

    private function identifierFrom(DecodedNip19 $decoded): string
    {
        $identifier = $decoded->data()['identifier'] ?? null;

        return \is_string($identifier) ? $identifier : '';
    }

    private function kindFrom(DecodedNip19 $decoded): int
    {
        $kind = $decoded->data()['kind'] ?? null;
        if (!\is_int($kind)) {
            throw new \UnexpectedValueException('Decoded NIP-19 address is missing an event kind.');
        }

        return $kind;
    }

    /**
     * @return list<string>
     */
    private function relaysFrom(DecodedNip19 $decoded): array
    {
        $relays = $decoded->data()['relays'] ?? [];
        if (!\is_array($relays)) {
            return [];
        }

        return \array_values(\array_filter($relays, static fn (mixed $relay): bool => \is_string($relay)));
    }
}
