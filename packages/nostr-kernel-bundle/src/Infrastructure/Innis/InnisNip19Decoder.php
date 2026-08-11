<?php

declare(strict_types=1);

namespace DecentNewsroom\NostrKernelBundle\Infrastructure\Innis;

use DecentNewsroom\NostrKernelBundle\Contract\Nip19\Nip19DecoderInterface;
use DecentNewsroom\NostrKernelBundle\Domain\Nip19\DecodedNip19;
use DecentNewsroom\NostrKernelBundle\Domain\Nip19\Nip19Type;
use Innis\Nostr\Core\Domain\Service\Bech32EncoderInterface;

final readonly class InnisNip19Decoder implements Nip19DecoderInterface
{
    public function __construct(private Bech32EncoderInterface $encoder)
    {
    }

    public function decode(string $value): DecodedNip19
    {
        $decoded = $this->encoder->decodeComplexEntity($value);
        $type = $decoded['type'] ?? null;
        if (!\is_string($type)) {
            throw new \UnexpectedValueException('Decoded NIP-19 value has no type.');
        }
        unset($decoded['type']);

        return new DecodedNip19(
            type: $this->resolveType($value, $type),
            data: $decoded,
        );
    }

    private function resolveType(string $value, string $type): Nip19Type
    {
        return match ($type) {
            'pubkey' => Nip19Type::NPUB,
            'profile' => Nip19Type::NPROFILE,
            'event' => str_starts_with(strtolower($value), 'note1')
                ? Nip19Type::NOTE
                : Nip19Type::NEVENT,
            'address' => Nip19Type::NADDR,
            default => throw new \UnexpectedValueException("Unsupported NIP-19 type: {$type}"),
        };
    }
}
