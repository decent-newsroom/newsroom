<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Fetches a best-effort web preview (title, description, image, site name)
 * for a URL by parsing Open Graph / Twitter Card / HTML head metadata.
 *
 * Used to render rich previews for NIP-22 comment `I` / `i` tags
 * (NIP-73 external identity, `K`/`k` = "web") on the single-event page.
 *
 * Results are cached per-URL in the default app cache. Negative results
 * are cached for a shorter TTL so transient network failures don't pin.
 */
final class WebPreviewService
{
    private const CACHE_PREFIX = 'web_preview_';
    private const TTL_OK = 86400;    // 24h
    private const TTL_FAIL = 900;    // 15 min
    private const MAX_BYTES = 262144; // 256 KiB of HTML is plenty for <head>
    private const TIMEOUT_SECONDS = 3.0;
    private const USER_AGENT = 'DecentNewsroomBot/1.0 (+https://decentnewsroom.com; web-preview)';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface $appCache,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Returns an associative array:
     *   [
     *     'url'         => canonical URL,
     *     'host'        => hostname,
     *     'title'       => ?string,
     *     'description' => ?string,
     *     'image'       => ?string,
     *     'siteName'    => ?string,
     *     'ok'          => bool,
     *   ]
     * Returns null if the URL is not fetchable/previewable (invalid scheme, etc.).
     */
    public function fetch(string $url): ?array
    {
        if (!$this->isPreviewable($url)) {
            return null;
        }

        $key = self::CACHE_PREFIX . sha1($url);

        return $this->appCache->get($key, function (ItemInterface $item) use ($url): array {
            $preview = $this->doFetch($url);
            $item->expiresAfter($preview['ok'] ? self::TTL_OK : self::TTL_FAIL);
            return $preview;
        });
    }

    private function isPreviewable(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return false;
        }
        $scheme = strtolower($parts['scheme']);
        return $scheme === 'http' || $scheme === 'https';
    }

    private function doFetch(string $url): array
    {
        $host = parse_url($url, PHP_URL_HOST) ?: '';
        $base = [
            'url'         => $url,
            'host'        => $host,
            'title'       => null,
            'description' => null,
            'image'       => null,
            'siteName'    => null,
            'ok'          => false,
        ];

        try {
            $response = $this->httpClient->request('GET', $url, [
                'timeout'    => self::TIMEOUT_SECONDS,
                'max_duration' => self::TIMEOUT_SECONDS + 1.0,
                'max_redirects' => 3,
                'headers'    => [
                    'User-Agent'      => self::USER_AGENT,
                    'Accept'          => 'text/html,application/xhtml+xml',
                    'Accept-Language' => 'en,*;q=0.5',
                ],
            ]);

            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 400) {
                $this->logger->debug('WebPreview: non-2xx response', [
                    'url' => $url, 'status' => $status,
                ]);
                return $base;
            }

            $contentType = $response->getHeaders(false)['content-type'][0] ?? '';
            if ($contentType !== '' && !str_contains(strtolower($contentType), 'html')) {
                return $base;
            }

            // Stream only the head: stop once we have enough bytes.
            $buffer = '';
            foreach ($this->httpClient->stream($response, self::TIMEOUT_SECONDS) as $chunk) {
                if ($chunk->isTimeout()) {
                    break;
                }
                $buffer .= $chunk->getContent();
                if (strlen($buffer) >= self::MAX_BYTES || str_contains($buffer, '</head>')) {
                    break;
                }
            }
            $response->cancel();

            if ($buffer === '') {
                return $base;
            }

            $parsed = $this->parseHead($buffer, $url);
            return array_merge($base, $parsed, ['ok' => $parsed['title'] !== null || $parsed['description'] !== null || $parsed['image'] !== null]);
        } catch (HttpExceptionInterface $e) {
            $this->logger->debug('WebPreview: HTTP exception', [
                'url' => $url, 'error' => $e->getMessage(),
            ]);
            return $base;
        } catch (\Throwable $e) {
            $this->logger->warning('WebPreview: unexpected error', [
                'url' => $url, 'error' => $e->getMessage(),
            ]);
            return $base;
        }
    }

    /**
     * Extract title, description, image and site name from the HTML head.
     * Prefers Open Graph, then Twitter Card, then generic <title> / meta description.
     *
     * @return array{title: ?string, description: ?string, image: ?string, siteName: ?string}
     */
    private function parseHead(string $html, string $baseUrl): array
    {
        $title = null;
        $description = null;
        $image = null;
        $siteName = null;

        // Limit to head if present, to avoid scanning full-page markup.
        if (preg_match('/<head\b[^>]*>(.*?)<\/head>/is', $html, $m)) {
            $html = $m[1];
        }

        // <meta ...> tags
        if (preg_match_all('/<meta\b[^>]+>/i', $html, $metaMatches)) {
            $ogTitle = null; $ogDesc = null; $ogImage = null; $ogSite = null;
            $twTitle = null; $twDesc = null; $twImage = null;
            $metaDesc = null;

            foreach ($metaMatches[0] as $tag) {
                $name = $this->extractAttr($tag, 'property') ?? $this->extractAttr($tag, 'name');
                $content = $this->extractAttr($tag, 'content');
                if ($name === null || $content === null || $content === '') {
                    continue;
                }
                $name = strtolower($name);
                switch ($name) {
                    case 'og:title':       $ogTitle = $content; break;
                    case 'og:description': $ogDesc = $content; break;
                    case 'og:image':
                    case 'og:image:url':
                    case 'og:image:secure_url':
                        $ogImage = $ogImage ?? $content; break;
                    case 'og:site_name':   $ogSite = $content; break;
                    case 'twitter:title':  $twTitle = $content; break;
                    case 'twitter:description': $twDesc = $content; break;
                    case 'twitter:image':
                    case 'twitter:image:src':
                        $twImage = $twImage ?? $content; break;
                    case 'description':    $metaDesc = $content; break;
                }
            }

            $title       = $ogTitle ?? $twTitle ?? null;
            $description = $ogDesc ?? $twDesc ?? $metaDesc ?? null;
            $image       = $ogImage ?? $twImage ?? null;
            $siteName    = $ogSite ?? null;
        }

        // Fallback to <title>
        if ($title === null && preg_match('/<title\b[^>]*>(.*?)<\/title>/is', $html, $tm)) {
            $title = html_entity_decode(trim(strip_tags($tm[1])), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($title === '') {
                $title = null;
            }
        }

        // Resolve relative image URL against the page URL
        if ($image !== null) {
            $image = $this->absolutize($image, $baseUrl);
        }

        return [
            'title'       => $title !== null ? $this->clean($title, 200) : null,
            'description' => $description !== null ? $this->clean($description, 400) : null,
            'image'       => $image,
            'siteName'    => $siteName !== null ? $this->clean($siteName, 80) : null,
        ];
    }

    private function extractAttr(string $tag, string $attr): ?string
    {
        if (preg_match('/\b' . preg_quote($attr, '/') . '\s*=\s*"([^"]*)"/i', $tag, $m)) {
            return $m[1];
        }
        if (preg_match("/\\b" . preg_quote($attr, '/') . "\\s*=\\s*'([^']*)'/i", $tag, $m)) {
            return $m[1];
        }
        return null;
    }

    private function absolutize(string $ref, string $baseUrl): string
    {
        if (preg_match('#^https?://#i', $ref)) {
            return $ref;
        }
        $b = parse_url($baseUrl);
        if (!is_array($b) || empty($b['scheme']) || empty($b['host'])) {
            return $ref;
        }
        $origin = $b['scheme'] . '://' . $b['host'] . (isset($b['port']) ? ':' . $b['port'] : '');
        if (str_starts_with($ref, '//')) {
            return $b['scheme'] . ':' . $ref;
        }
        if (str_starts_with($ref, '/')) {
            return $origin . $ref;
        }
        $path = $b['path'] ?? '/';
        $path = substr($path, 0, strrpos($path, '/') + 1) ?: '/';
        return $origin . $path . $ref;
    }

    private function clean(string $s, int $max): string
    {
        $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $s = preg_replace('/\s+/u', ' ', trim($s)) ?? $s;
        if (mb_strlen($s) > $max) {
            $s = rtrim(mb_substr($s, 0, $max - 1)) . '…';
        }
        return $s;
    }
}

