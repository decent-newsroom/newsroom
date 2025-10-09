<?php

namespace App\Service;

use nostriphant\NIP19\Bech32;
use Psr\Log\LoggerInterface;

readonly class NostrLinkParser
{
    private const NOSTR_LINK_PATTERN = '/(?:nostr:)(nevent1[a-z0-9]+|naddr1[a-z0-9]+|nprofile1[a-z0-9]+|note1[a-z0-9]+|npub1[a-z0-9]+)/';

    private const URL_PATTERN = '/https?:\/\/[\w\-\.\?\,\'\/\\\+&%@\?\$#_=:\(\)~;]+/i';

    public function __construct(
        private LoggerInterface $logger
    ) {}

    /**
     * Parse content for Nostr links and return structured data
     *
     * @param string $content The content to parse
     * @return array Array of detected Nostr links with their type and decoded data
     */
    public function parseLinks(string $content): array
    {
        $links = [];
        $links = array_merge(
            $this->parseUrlsWithNostrIds($content),
            $this->parseBareNostrIdentifiers($content)
        );
        // Sort by position to maintain the original order in the text
        usort($links, fn($a, $b) => $a['position'] <=> $b['position']);
        return $links;
    }

    private function parseUrlsWithNostrIds(string $content): array
    {
        $links = [];
        if (preg_match_all(self::URL_PATTERN, $content, $urlMatches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($urlMatches as $urlMatch) {
                $url = $urlMatch[0][0];
                $position = $urlMatch[0][1];
                $nostrId = null;
                $nostrType = null;
                $nostrData = null;
                if (preg_match(self::NOSTR_LINK_PATTERN, $url, $nostrMatch)) {
                    $nostrId = $nostrMatch[1];
                    try {
                        $decoded = new Bech32($nostrId);
                        $nostrType = $decoded->type;
                        $nostrData = $decoded->data;
                    } catch (\Exception $e) {
                        $this->logger->info('Failed to decode Nostr identifier in URL', [
                            'identifier' => $nostrId,
                            'error' => $e->getMessage()
                        ]);
                    }
                }
                $links[] = [
                    'type' => $nostrType ?? 'url',
                    'identifier' => $nostrId,
                    'full_match' => $url,
                    'position' => $position,
                    'data' => $nostrData,
                    'is_url' => true
                ];
            }
        }
        return $links;
    }

    private function parseBareNostrIdentifiers(string $content): array
    {
        $links = [];


        if (preg_match_all(self::NOSTR_LINK_PATTERN, $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {

            // If match starts with nostr:, continue otherwise check if part of URL
            if (!(str_starts_with($matches[0][0][0], 'nostr:'))) {
                // Check if the match is part of a URL, as path or query parameter
                $urlPattern = '/https?:\/\/[\w\-.?,\'\/+&%$#@_=:()~;]+/i';
                foreach ($matches as $key => $match) {
                    $position = $match[0][1];
                    // Check if the match is preceded by a URL
                    $precedingContent = substr($content, 0, $position);

                    if (preg_match($urlPattern, $precedingContent)) {
                        // If the match is preceded by a URL, skip it
                        unset($matches[$key]);
                    }
                }
            }

            foreach ($matches as $match) {
                $identifier = $match[1][0];
                $position = $match[0][1];
                // This check will be handled in parseLinks by sorting and merging
                try {
                    $decoded = new Bech32($identifier);
                    $links[] = [
                        'type' => $decoded->type,
                        'identifier' => $identifier,
                        'full_match' => $match[0][0],
                        'position' => $position,
                        'data' => $decoded->data,
                        'is_url' => false
                    ];
                } catch (\Exception $e) {
                    $this->logger->info('Failed to decode Nostr identifier', [
                        'identifier' => $identifier,
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }

        return $links;
    }

}
