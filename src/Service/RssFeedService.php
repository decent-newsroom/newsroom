<?php

namespace App\Service;

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

        // RSS 2.0
        if (isset($xml->channel->item)) {
            foreach ($xml->channel->item as $item) {
                $items[] = $this->parseRssItem($item);
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
                $items[] = $this->parseAtomEntry($entry);
            }
            $feedMeta['title'] = (string)($xml->title ?? '');
            $feedMeta['description'] = (string)($xml->subtitle ?? '');
            $feedMeta['link'] = (string)($xml->link['href'] ?? '');
            $feedMeta['image'] = (string)($xml->logo ?? '');
        }

        return ['feed' => $feedMeta, 'items' => $items];
    }

    private function parseRssItem(\SimpleXMLElement $item): array
    {
        $namespaces = $item->getNamespaces(true);
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

        // media:content image
        $imageUrl = null;
        if (isset($namespaces['media'])) {
            $mediaChildren = $item->children($namespaces['media']);
            if (isset($mediaChildren->content)) {
                foreach ($mediaChildren->content as $mediaContent) {
                    $medium = (string) $mediaContent['medium'];
                    if ($medium === 'image' || $medium === '') {
                        $imageUrl = (string) $mediaContent['url'];
                        break;
                    }
                }
            }
        }
        // ghost/bitnami quirk
        if (!$imageUrl && isset($item->content) && isset($item->content->_url)) {
            $medium = isset($item->content->_medium) ? (string)$item->content->_medium : '';
            if ($medium === 'image' || $medium === '') {
                $imageUrl = (string)$item->content->_url;
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

    private function parseAtomEntry(\SimpleXMLElement $entry): array
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
        ];
    }
}
