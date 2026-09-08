<?php

declare(strict_types=1);

namespace App\Service\Nostr;

use Innis\Nostr\Core\Domain\ValueObject\Identity\PrivateKey;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use nostriphant\NIP19\Bech32;

/**
 * Small application adapter for NIP-19 public-key conversions.
 *
 * Keeping this conversion at the application boundary prevents controllers
 * and components from depending on a transport package's key helper.
 */
final class NostrKeyService
{
    public function convertToHex(string $identifier): string
    {
        $identifier = strtolower(trim($identifier));
        if (str_starts_with($identifier, 'nostr:')) {
            $identifier = substr($identifier, 6);
        }

        if (preg_match('/^[a-f0-9]{64}$/', $identifier) === 1) {
            return $identifier;
        }

        $decoded = (new Bech32($identifier))();
        $hex = is_string($decoded) ? $decoded : ($decoded->pubkey ?? null);
        if (!is_string($hex) || PublicKey::fromHex($hex) === null) {
            throw new \InvalidArgumentException('Invalid Nostr public key.');
        }

        return strtolower($hex);
    }

    public function convertPublicKeyToBech32(string $hexPubkey): string
    {
        $publicKey = PublicKey::fromHex(strtolower(trim($hexPubkey)));
        if ($publicKey === null) {
            throw new \InvalidArgumentException('Invalid Nostr public key.');
        }

        return $publicKey->toBech32();
    }

    public function generatePrivateKey(): string
    {
        return PrivateKey::generate()->toHex();
    }
}
