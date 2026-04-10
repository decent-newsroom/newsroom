<?php

declare(strict_types=1);

namespace App\Twig;

use Psr\Log\LoggerInterface;
use Symfony\UX\TwigComponent\ComponentRendererInterface;
use Twig\Extension\RuntimeExtensionInterface;

/**
 * Twig runtime that resolves deferred Nostr embed placeholders at
 * template render time by delegating to the Molecules:NostrEmbed
 * Twig component.
 *
 * The Converter emits lightweight `<div class="nostr-deferred-embed" …>`
 * elements for nostr references that couldn't be resolved from local
 * data during Markdown→HTML conversion.  This runtime finds those
 * elements and replaces each with the rendered NostrEmbed component,
 * which does the actual DB/Redis lookup in its mount() method.
 */
class NostrEmbedRuntime implements RuntimeExtensionInterface
{
    private const RE_DEFERRED = '/<div\s+class="nostr-deferred-embed"\s+data-nostr-bech="([^"]+)"\s+data-nostr-type="([^"]+)"[^>]*><\/div>/';

    public function __construct(
        private readonly ComponentRendererInterface $componentRenderer,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Resolve deferred Nostr embed placeholders in the given HTML.
     *
     * Called as a Twig filter: {{ content|resolve_nostr_embeds }}
     */
    public function resolveEmbeds(?string $html): string
    {
        if ($html === null || $html === '') {
            return '';
        }

        // Quick bail-out: no deferred embeds
        if (!str_contains($html, 'nostr-deferred-embed')) {
            return $html;
        }

        return preg_replace_callback(self::RE_DEFERRED, function (array $m): string {
            $bech = $m[1];
            $type = $m[2];

            try {
                return $this->componentRenderer->createAndRender('Molecules:NostrEmbed', [
                    'bech' => $bech,
                    'type' => $type,
                ]);
            } catch (\Throwable $e) {
                $this->logger->warning('NostrEmbedRuntime: component render failed', [
                    'bech' => $bech,
                    'type' => $type,
                    'error' => $e->getMessage(),
                ]);
                // Return the original placeholder so nothing is lost
                return $m[0];
            }
        }, $html) ?? $html;
    }
}

