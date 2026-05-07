<?php

declare(strict_types=1);

namespace App\Service;

use nostriphant\NIP19\Bech32;
use nostriphant\NIP19\Data\NAddr;
use nostriphant\NIP19\Data\NEvent;
use nostriphant\NIP19\Data\Note;
use Psr\Log\LoggerInterface;

/**
 * Scans an article's processed HTML for deferred embed placeholders and
 * extracts the underlying event IDs, coordinates, and relay hints so they
 * can be bulk-fetched from relays.
 *
 * The Converter emits `<div class="nostr-deferred-embed" data-nostr-bech="…"
 * data-nostr-type="…"></div>` for every nostr: reference it could not resolve
 * from the local DB at conversion time.  This service reads those divs back
 * out into typed reference lists.
 */
final class EmbedReferenceExtractor
{
    /**
     * Matches: <div class="nostr-deferred-embed" data-nostr-bech="X" data-nostr-type="Y"></div>
     * Attribute order in the produced HTML is always bech then type.
     */
    private const RE = '/<div\s+class="nostr-deferred-embed"\s+data-nostr-bech="([^"]+)"\s+data-nostr-type="([^"]+)"[^>]*><\/div>/';

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Parse deferred embed placeholders out of processed article HTML.
     *
     * @param  string|null $html
     * @return array{eventIds: string[], coordinates: string[], relayHints: string[]}
     */
    public function extractFromHtml(?string $html): array
    {
        $result = ['eventIds' => [], 'coordinates' => [], 'relayHints' => []];

        if ($html === null || $html === '' || !str_contains($html, 'nostr-deferred-embed')) {
            return $result;
        }

        if (!preg_match_all(self::RE, $html, $matches, PREG_SET_ORDER)) {
            return $result;
        }

        $seenIds   = [];
        $seenCoords = [];
        $seenRelays = [];

        foreach ($matches as $m) {
            $bech = $m[1];
            $type = $m[2];

            try {
                $decoded = new Bech32($bech);

                switch ($decoded->type) {
                    case 'note':
                        /** @var Note $obj */
                        $obj = $decoded->data;
                        $id  = (string) $obj->data;
                        if (!isset($seenIds[$id])) {
                            $seenIds[$id]         = true;
                            $result['eventIds'][] = $id;
                        }
                        break;

                    case 'nevent':
                        /** @var NEvent $obj */
                        $obj = $decoded->data;
                        $id  = (string) $obj->id;
                        if (!isset($seenIds[$id])) {
                            $seenIds[$id]         = true;
                            $result['eventIds'][] = $id;
                        }
                        // Collect relay hints
                        foreach ($obj->relays ?? [] as $relay) {
                            if (is_string($relay) && !isset($seenRelays[$relay])) {
                                $seenRelays[$relay]      = true;
                                $result['relayHints'][]  = $relay;
                            }
                        }
                        break;

                    case 'naddr':
                        /** @var NAddr $obj */
                        $obj   = $decoded->data;
                        $coord = $obj->kind . ':' . $obj->pubkey . ':' . $obj->identifier;
                        if (!isset($seenCoords[$coord])) {
                            $seenCoords[$coord]        = true;
                            $result['coordinates'][]   = $coord;
                        }
                        // Collect relay hints
                        foreach ($obj->relays ?? [] as $relay) {
                            if (is_string($relay) && !isset($seenRelays[$relay])) {
                                $seenRelays[$relay]      = true;
                                $result['relayHints'][]  = $relay;
                            }
                        }
                        break;
                }
            } catch (\Throwable $e) {
                $this->logger->debug('EmbedReferenceExtractor: failed to decode bech32', [
                    'bech'  => substr($bech, 0, 20) . '…',
                    'type'  => $type,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $result;
    }
}

