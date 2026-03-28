<?php

namespace App\Service\RSS;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class RssFeedService
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger
    ) {}

    public function fetchFeed(string $feedUrl): array
    {
        try {
            $this->logger->info('Fetching RSS feed', ['url' => $feedUrl]);

            $response = $this->httpClient->request('GET', $feedUrl, [
                'timeout' => 30,
                'headers' => ['User-Agent' => 'Newsroom RSS Aggregator/1.0'],
            ]);

            if ($response->getStatusCode() !== 200) {
                throw new \Exception(sprintf('HTTP error %d when fetching feed', $response->getStatusCode()));
            }

            $xmlContent = $response->getContent();
            $parsed = $this->parseRssFeed($xmlContent);

            $this->logger->info('RSS feed fetched successfully', [
                'url' => $feedUrl,
                'items' => count($parsed['items']),
            ]);

            return $parsed;
        } catch (\Exception $e) {
            $this->logger->error('Failed to fetch RSS feed', [
                'url' => $feedUrl,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function parseRssFeed(string $xmlContent): array
    {
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xmlContent);

        if ($xml === false) {
            $errors = libxml_get_errors();
            libxml_clear_errors();
            throw new \Exception('Failed to parse RSS XML: ' . json_encode($errors));
        }

        $items = [];
        $feedMeta = [
            'title' => null,
            'description' => null,
            'link' => null,
            'image' => null,
        ];

        // Resolve all namespaces from the document root so that prefixes
        // declared on <rss>/<feed> (e.g. xmlns:media) are always available
        // even when individual <item>/<entry> elements don't redeclare them.
        $rootNamespaces = $xml->getDocNamespaces(true);

        // RSS 2.0
        if (isset($xml->channel->item)) {
            foreach ($xml->channel->item as $item) {
                $items[] = $this->parseRssItem($item, $rootNamespaces);
            }
            $feedMeta['title'] = (string)($xml->channel->title ?? '');
            $feedMeta['description'] = (string)($xml->channel->description ?? '');
            $feedMeta['link'] = (string)($xml->channel->link ?? '');
            if (isset($xml->channel->image->url)) {
                $feedMeta['image'] = (string)$xml->channel->image->url;
            }
        }
        // Atom
        elseif (isset($xml->entry)) {
            foreach ($xml->entry as $entry) {
                $items[] = $this->parseAtomEntry($entry, $rootNamespaces);
            }
            $feedMeta['title'] = (string)($xml->title ?? '');
            $feedMeta['description'] = (string)($xml->subtitle ?? '');
            $feedMeta['link'] = (string)($xml->link['href'] ?? '');
            $feedMeta['image'] = (string)($xml->logo ?? '');
        }

        return ['feed' => $feedMeta, 'items' => $items];
    }

    private function parseRssItem(\SimpleXMLElement $item, array $namespaces): array
    {
        $content = '';

        // content:encoded
        if (isset($namespaces['content'])) {
            $contentChildren = $item->children($namespaces['content']);
            if (isset($contentChildren->encoded)) {
                $content = (string) $contentChildren->encoded;
            }
        }
        if ($content === '') {
            $content = (string) ($item->description ?? '');
        }

        // categories
        $categories = [];
        if (isset($item->category)) {
            foreach ($item->category as $category) {
                $categories[] = (string) $category;
            }
        }

        // media:content / media:thumbnail image (via XPath to avoid
        // SimpleXML collision between the 'content' namespace prefix
        // and the 'content' local name in <media:content>)
        $imageUrl = $this->extractMediaImage($item, $namespaces);

        // ghost/bitnami quirk — attribute-style access on non-namespaced <content>
        if (!$imageUrl) {
            foreach ($item->children() as $child) {
                if ($child->getName() === 'content') {
                    $url = (string) $child['url'];
                    if ($url !== '') {
                        $medium = (string) ($child['medium'] ?? '');
                        if ($medium === 'image' || $medium === '') {
                            $imageUrl = $url;
                        }
                    }
                    break;
                }
            }
        }
        // enclosure fallback (type="image/*")
        if (!$imageUrl && isset($item->enclosure)) {
            $encType = (string) $item->enclosure['type'];
            if (str_starts_with($encType, 'image/')) {
                $encUrl = (string) $item->enclosure['url'];
                if ($encUrl !== '') {
                    $imageUrl = $encUrl;
                }
            }
        }

        // pubDate → timestamp int
        $pubTs = null;
        if (isset($item->pubDate)) {
            try {
                $pubTs = (new \DateTimeImmutable((string)$item->pubDate))->getTimestamp();
            } catch (\Throwable $e) {
                $pubTs = null;
            }
        }

        return [
            'title'       => (string) ($item->title ?? ''),
            'link'        => (string) ($item->link ?? ''),
            'pubDate'     => $pubTs,
            'description' => html_entity_decode(strip_tags((string)($item->description ?? ''))),
            'content'     => (string)$content,
            'categories'  => $categories,
            'guid'        => (string) ($item->guid ?? ''),
            'image'       => $imageUrl,
        ];
    }

    private function parseAtomEntry(\SimpleXMLElement $entry, array $namespaces): array
    {
        // link
        $link = '';
        if (isset($entry->link)) {
            foreach ($entry->link as $l) {
                if ((string)$l['rel'] === 'alternate' || !isset($l['rel'])) {
                    $link = (string)$l['href'];
                    break;
                }
            }
        }

        // content
        $content = (string) ($entry->content ?? $entry->summary ?? '');

        // categories
        $categories = [];
        if (isset($entry->category)) {
            foreach ($entry->category as $category) {
                $categories[] = (string) $category['term'];
            }
        }

        // media:content / media:thumbnail image
        $imageUrl = $this->extractMediaImage($entry, $namespaces);

        // pubDate → timestamp int
        $pubTs = null;
        try {
            if (isset($entry->published)) {
                $pubTs = (new \DateTimeImmutable((string)$entry->published))->getTimestamp();
            } elseif (isset($entry->updated)) {
                $pubTs = (new \DateTimeImmutable((string)$entry->updated))->getTimestamp();
            }
        } catch (\Throwable $e) {
            $pubTs = null;
        }

        return [
            'title'       => (string) ($entry->title ?? ''),
            'link'        => $link,
            'pubDate'     => $pubTs,
            'description' => html_entity_decode(strip_tags((string)($entry->summary ?? ''))),
            'content'     => $content,
            'categories'  => $categories,
            'guid'        => (string) ($entry->id ?? ''),
            'image'       => $imageUrl,
        ];
    }

    /**
     * Extract an image URL from media:content or media:thumbnail using XPath.
     *
     * XPath is used instead of SimpleXML property access because feeds that
     * declare both xmlns:content (for content:encoded) and xmlns:media (for
     * media:content) cause a collision: the local name "content" in
     * <media:content> clashes with the "content" namespace prefix, making
     * $element->children($mediaUri)->content unreliable.
     */
    private function extractMediaImage(\SimpleXMLElement $element, array $namespaces): ?string
    {
        if (!isset($namespaces['media'])) {
            return null;
        }

        $mediaUri = $namespaces['media'];
        $element->registerXPathNamespace('media', $mediaUri);

        // media:content — look for image entries
        $mediaContents = $element->xpath('media:content');
        if ($mediaContents) {
            foreach ($mediaContents as $mc) {
                $url = (string) $mc['url'];
                if ($url === '') {
                    continue;
                }
                $medium = (string) $mc['medium'];
                $type = (string) $mc['type'];
                if ($medium === 'image'
                    || ($medium === '' && str_starts_with($type, 'image/'))
                    || ($medium === '' && $type === '' && $this->looksLikeImageUrl($url))
                ) {
                    return $url;
                }
            }
        }

        // media:thumbnail fallback
        $thumbnails = $element->xpath('media:thumbnail');
        if ($thumbnails) {
            foreach ($thumbnails as $thumb) {
                $url = (string) $thumb['url'];
                if ($url !== '') {
                    return $url;
                }
            }
        }

        return null;
    }

    /**
     * Heuristic: does the URL path end with a common image extension?
     */
    private function looksLikeImageUrl(string $url): bool
    {
        $path = strtolower(parse_url($url, PHP_URL_PATH) ?? '');
        return (bool) preg_match('/\.(jpe?g|png|gif|webp|avif|svg|bmp|tiff?)$/i', $path);
    }
}
