<?php

declare(strict_types=1);

namespace App\Twig;

use App\Util\CommonMark\Converter;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
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
    private const MAX_EMBED_DEPTH = 8;
    private int $embedDepth = 0;
    /** @var array<string, true> */
    private array $activeReferences = [];

    public function __construct(
        private readonly ComponentRendererInterface $componentRenderer,
        private readonly LoggerInterface $logger,
        private readonly Converter $converter,
        private readonly Security $security,
    ) {}

    /**
     * Resolve deferred Nostr embed placeholders in the given HTML.
     *
     * Called as a Twig filter: {{ content|resolve_nostr_embeds }}
     *
     * @param array<array-key, mixed>|null $tags
     */
    public function resolveEmbeds(
        ?string $html,
        ?string $rawContent = null,
        ?int $kind = null,
        ?array $tags = null,
        bool $renderMedia = true,
        bool $checkLegacyCards = true,
        bool $highlightOnly = false,
    ): string
    {
        if ($html === null || $html === '') {
            return '';
        }

        // Nested conversions may be persisted in shared HTML caches.
        if ($this->converter->isConverting()) {
            return $html;
        }

        // Older cached HTML may contain complete event cards. Rebuild from
        // source text before showing it to a guest, without modifying the cache.
        if ($checkLegacyCards && $this->security->getUser() === null && str_contains($html, 'embedded-event-card')) {
            if ($rawContent === null) {
                $this->logger->warning('Cannot safely render cached Nostr cards without their source content.');
                return '';
            }

            try {
                $html = $this->converter->withLocalRendering(
                    fn (): string => $this->converter->convertToHTML($rawContent, null, $kind, $tags, $renderMedia),
                );
            } catch (\Throwable $exception) {
                $this->logger->error('Could not rebuild cached Nostr cards for a guest.', ['exception' => $exception]);
                return '<pre>' . htmlspecialchars($rawContent, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</pre>';
            }
        }

        // Quick bail-out: no deferred embeds
        if (!str_contains($html, 'nostr-deferred-embed')) {
            return $html;
        }

        return preg_replace_callback(self::RE_DEFERRED, function (array $m) use ($highlightOnly): string {
            $bech = $m[1];
            $type = $m[2];

            if ($highlightOnly && $type !== 'highlight-nevent' && $type !== 'highlight-naddr') {
                return $m[0];
            }
            if ($this->embedDepth >= self::MAX_EMBED_DEPTH || isset($this->activeReferences[$bech])) {
                $this->logger->warning('NostrEmbedRuntime: cyclic or deeply nested embed omitted.', ['bech' => $bech]);
                return '';
            }

            $parameters = ['bech' => $bech, 'type' => $type];
            $isHighlight = $type === 'highlight-nevent' || $type === 'highlight-naddr';
            if ($isHighlight) {
                $parameters['type'] = substr($type, strlen('highlight-'));
                $parameters['renderHighlightCard'] = true;
            }
            ++$this->embedDepth;
            $this->activeReferences[$bech] = true;
            try {
                $render = fn (): string => $this->componentRenderer->createAndRender('Molecules:NostrEmbed', $parameters);
                return $isHighlight && $this->security->getUser() === null
                    ? $this->converter->withLocalRendering($render)
                    : $render();
            } catch (\Throwable $e) {
                $this->logger->warning('NostrEmbedRuntime: component render failed', [
                    'bech' => $bech,
                    'type' => $type,
                    'error' => $e->getMessage(),
                ]);
                // Return the original placeholder so nothing is lost
                return $m[0];
            } finally {
                unset($this->activeReferences[$bech]);
                --$this->embedDepth;
            }
        }, $html) ?? $html;
    }
}
