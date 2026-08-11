<?php

declare(strict_types=1);

namespace App\Twig;

use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Twig filters for Nostr key conversions.
 */
class NostrExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('npub_to_hex', $this->npubToHex(...)),
            new TwigFilter('hex_to_npub', $this->hexToNpub(...)),
        ];
    }

    public function npubToHex(?string $npub): string
    {
        if ($npub === null || $npub === '') {
            return '';
        }

        try {
            return (static function (string $npub): string { $npub = strtolower(trim($npub)); if (str_starts_with($npub, 'nostr:')) { $npub = substr($npub, 6); } return PublicKey::fromBech32($npub)?->toHex() ?? throw new \InvalidArgumentException('Not a valid npub'); })((string) ($npub));
        } catch (\Throwable) {
            return '';
        }
    }

    public function hexToNpub(?string $hex): string
    {
        if ($hex === null || $hex === '') {
            return '';
        }

        try {
            return (static function (string $pubkey): string { return PublicKey::fromHex(strtolower(trim($pubkey)))?->toBech32() ?? throw new \InvalidArgumentException('Not a valid hex pubkey'); })((string) ($hex));
        } catch (\Throwable) {
            return '';
        }
    }
}

