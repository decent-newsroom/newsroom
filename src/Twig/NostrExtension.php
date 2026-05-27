<?php

declare(strict_types=1);

namespace App\Twig;

use App\Util\NostrKeyUtil;
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
            return NostrKeyUtil::npubToHex($npub);
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
            return NostrKeyUtil::hexToNpub($hex);
        } catch (\Throwable) {
            return '';
        }
    }
}

