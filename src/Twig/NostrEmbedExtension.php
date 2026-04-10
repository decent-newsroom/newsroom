<?php

declare(strict_types=1);

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Registers the `resolve_nostr_embeds` Twig filter.
 *
 * Usage:  {{ content|resolve_nostr_embeds|raw }}
 */
class NostrEmbedExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter(
                'resolve_nostr_embeds',
                [NostrEmbedRuntime::class, 'resolveEmbeds'],
                ['is_safe' => ['html']],
            ),
        ];
    }
}
