<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Service for fetching and parsing RSS feeds
 */
class RssFeedService
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Fetch and parse an RSS feed from a URL
     *
     * @param string $feedUrl The URL of the RSS feed
     * @return array Array of feed items, each containing: title, link, pubDate, description, content, categories
     * @throws \Exception if feed cannot be fetched or parsed
     */
    public function fetchFeed(string $feedUrl): array
    {
        try {
            $this->logger->info('Fetching RSS feed', ['url' => $feedUrl]);

            $response = $this->httpClient->request('GET', $feedUrl, [
                'timeout' => 30,
                'headers' => [
                    'User-Agent' => 'Newsroom RSS Aggregator/1.0',
                ],
            ]);

            if ($response->getStatusCode() !== 200) {
                throw new \Exception(sprintf('HTTP error %d when fetching feed', $response->getStatusCode()));
            }

            $xmlContent = $response->getContent();
            $items = $this->parseRssFeed($xmlContent);

            $this->logger->info('RSS feed fetched successfully', [
                'url' => $feedUrl,
                'items' => count($items),
            ]);

            return $items;
        } catch (\Exception $e) {
            $this->logger->error('Failed to fetch RSS feed', [
                'url' => $feedUrl,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Parse RSS XML content into structured array
     *
     * @param string $xmlContent Raw XML content
     * @return array Array of parsed feed items
     * @throws \Exception if XML parsing fails
     */
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

        // Handle both RSS 2.0 and Atom feeds
        if (isset($xml->channel->item)) {
            // RSS 2.0
            foreach ($xml->channel->item as $item) {
                $items[] = $this->parseRssItem($item);
            }
        } elseif (isset($xml->entry)) {
            // Atom feed
            foreach ($xml->entry as $entry) {
                $items[] = $this->parseAtomEntry($entry);
            }
        }

        return $items;
    }

    /**
     * Parse a single RSS 2.0 item
     */
    private function parseRssItem(\SimpleXMLElement $item): array
    {
        $namespaces = $item->getNamespaces(true);
        $content = '';

        // Try to get full content from content:encoded or description
        if (isset($namespaces['content'])) {
            $contentChildren = $item->children($namespaces['content']);
            if (isset($contentChildren->encoded)) {
                $content = (string) $contentChildren->encoded;
            }
        }

        if (empty($content)) {
            $content = (string) ($item->description ?? '');
        }

        // Extract categories
        $categories = [];
        if (isset($item->category)) {
            foreach ($item->category as $category) {
                $categories[] = (string) $category;
            }
        }

        // Parse publication date
        $pubDate = null;
        if (isset($item->pubDate)) {
            $pubDate = new \DateTimeImmutable((string) $item->pubDate);
        }

        return [
            'title' => (string) ($item->title ?? ''),
            'link' => (string) ($item->link ?? ''),
            'pubDate' => $pubDate,
            'description' => (string) ($item->description ?? ''),
            'content' => $content,
            'categories' => $categories,
            'guid' => (string) ($item->guid ?? ''),
        ];
    }

    /**
     * Parse a single Atom entry
     */
    private function parseAtomEntry(\SimpleXMLElement $entry): array
    {
        $namespaces = $entry->getNamespaces(true);

        // Get link
        $link = '';
        if (isset($entry->link)) {
            foreach ($entry->link as $l) {
                if ((string) $l['rel'] === 'alternate' || !isset($l['rel'])) {
                    $link = (string) $l['href'];
                    break;
                }
            }
        }

        // Get content
        $content = (string) ($entry->content ?? $entry->summary ?? '');

        // Get categories/tags
        $categories = [];
        if (isset($entry->category)) {
            foreach ($entry->category as $category) {
                $categories[] = (string) $category['term'];
            }
        }

        // Parse publication date
        $pubDate = null;
        if (isset($entry->published)) {
            $pubDate = new \DateTimeImmutable((string) $entry->published);
        } elseif (isset($entry->updated)) {
            $pubDate = new \DateTimeImmutable((string) $entry->updated);
        }

        return [
            'title' => (string) ($entry->title ?? ''),
            'link' => $link,
            'pubDate' => $pubDate,
            'description' => (string) ($entry->summary ?? ''),
            'content' => $content,
            'categories' => $categories,
            'guid' => (string) ($entry->id ?? ''),
        ];
    }
}

