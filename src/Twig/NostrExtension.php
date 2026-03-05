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
        ];
    }

    public function npubToHex(string $npub): string
    {
        try {
            return NostrKeyUtil::npubToHex($npub);
        } catch (\Throwable) {
            return '';
        }
    }
}

