<?php

declare(strict_types=1);

namespace App\Util;

use App\Entity\Article;

final class BlockedArticleDomainPolicy
{
    public const DOMAINS = ['y5.pics', 'go.cbrop.com', 'i.postimg.cc'];

    public function findBlockedDomain(Article $article): ?string
    {
        $texts = [
            $article->getContent() ?? '',
            $article->getImage() ?? '',
            $article->getTitle() ?? '',
            $article->getSummary() ?? '',
        ];
        foreach ($article->getRaw()['tags'] ?? [] as $tag) {
            if (is_array($tag)) {
                foreach (array_slice($tag, 1) as $value) {
                    if (is_string($value)) {
                        $texts[] = $value;
                    }
                }
            }
        }

        $domains = implode('|', array_map(static fn (string $domain): string => preg_quote($domain, '~'), self::DOMAINS));
        // Consume whole URLs first so a blocked domain in a safe URL's path is not a host match.
        $pattern = '~(?:https?://|//)[^\s<>"\'`]+|(?<![a-z0-9_/@.-])(?:[a-z0-9-]+\.)*(?:'
            . $domains . ')\.?(?=[:/\s<>"\'`)\],!?;]|$)(?::[0-9]+)?(?:/[^\s<>"\'`]*)?~i';

        foreach ($texts as $text) {
            preg_match_all($pattern, $text, $matches);
            foreach ($matches[0] as $url) {
                $url = rtrim($url, ".,;!?) ]}");
                if (str_starts_with($url, '//')) {
                    $url = 'https:' . $url;
                } elseif (!preg_match('~^https?://~i', $url)) {
                    $url = 'https://' . $url;
                }
                $host = parse_url($url, PHP_URL_HOST);
                if (!is_string($host)) {
                    continue;
                }
                $host = strtolower(rtrim($host, '.'));
                foreach (self::DOMAINS as $domain) {
                    if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                        return $domain;
                    }
                }
            }
        }

        return null;
    }
}
