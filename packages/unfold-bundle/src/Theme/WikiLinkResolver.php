<?php

declare(strict_types=1);

namespace DecentNewsroom\UnfoldBundle\Theme;

use DecentNewsroom\UnfoldBundle\Config\ContentReference;
use DecentNewsroom\UnfoldBundle\Content\PostData;
use DecentNewsroom\UnfoldBundle\Contract\MarkdownConverterInterface;
use DecentNewsroom\UnfoldBundle\Http\PublicationUrlGenerator;

/**
 * Resolve wiki subjects only against the publication's already-authorized
 * inventory. Ambiguous/global subjects go to an explicit reader search.
 */
final class WikiLinkResolver
{
    /** @param list<PostData> $posts */
    public static function convert(PostData $post, array $posts, MarkdownConverterInterface $converter, string $platformBaseUrl): string
    {
        $links = [];
        $prefix = 'UNFOLDWIKI' . hash('sha256', $post->content);
        $source = preg_replace_callback('/\[\[([^\]\r\n]+)\]\]/u', static function (array $match) use (&$links, $prefix): string {
            $token = $prefix . count($links) . 'END';
            $links[$token] = $match[1];

            return $token;
        }, $post->content) ?? $post->content;
        $html = $converter->convertToHTML($source, 'asciidoc', $post->kind, $post->tags);
        if ($links === []) {
            return $html;
        }

        $document = new \DOMDocument('1.0', 'UTF-8');
        $previousErrors = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8"><div id="unfold-wiki-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
            $xpath = new \DOMXPath($document);
            $nodes = iterator_to_array($xpath->query('//text()'));
            foreach ($nodes as $node) {
                $eligible = $xpath->query('ancestor::pre|ancestor::code|ancestor::a|ancestor::script|ancestor::style', $node)->length === 0;
                $parts = preg_split('/(' . $prefix . '\d+END)/', $node->nodeValue, -1, PREG_SPLIT_DELIM_CAPTURE);
                if (count($parts) === 1) {
                    continue;
                }
                foreach ($parts as $part) {
                    if (!isset($links[$part]) || !$eligible) {
                        $text = isset($links[$part]) ? '[[' . $links[$part] . ']]' : $part;
                        $replacement = $document->createTextNode($text);
                    } else {
                        [$target, $label] = array_pad(explode('|', $links[$part], 2), 2, null);
                        $replacement = $document->createElement('a');
                        $replacement->setAttribute('href', self::url($target, $posts, $platformBaseUrl));
                        $replacement->appendChild($document->createTextNode($label ?? $target));
                    }
                    $node->parentNode->insertBefore($replacement, $node);
                }
                $node->parentNode->removeChild($node);
            }
            // A token appearing in a source attribute is data, never a link.
            foreach ($xpath->query('//@*') as $attribute) {
                foreach ($links as $token => $target) {
                    $attribute->nodeValue = str_replace($token, '[[' . $target . ']]', $attribute->nodeValue);
                }
            }
            $root = $document->getElementById('unfold-wiki-root');
            if ($root === null) {
                return $html;
            }
            $result = '';
            foreach ($root->childNodes as $child) {
                $result .= $document->saveHTML($child);
            }

            return $result;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrors);
        }
    }

    /** @param list<PostData> $posts */
    private static function url(string $target, array $posts, string $platformBaseUrl): string
    {
        $target = trim($target);
        try {
            $reference = ContentReference::fromInput($target);
            foreach ($posts as $post) {
                if ($post->isPublic() && $post->coordinate === $reference->coordinate) {
                    return PublicationUrlGenerator::postPath($post);
                }
            }
        } catch (\InvalidArgumentException) {
        }
        $subject = self::normalizeSubject($target);
        $matches = [];
        foreach ($posts as $post) {
            if ($post->isPublic() && $post->kind === 30818 && $post->slug === $subject) {
                $matches[$post->coordinate] = $post;
            }
        }
        if (count($matches) === 1) {
            return PublicationUrlGenerator::postPath(reset($matches));
        }

        return rtrim($platformBaseUrl, '/') . '/search?q=' . rawurlencode($subject);
    }

    private static function normalizeSubject(string $subject): string
    {
        $subject = mb_strtolower($subject, 'UTF-8');
        $subject = preg_replace('/\s+/u', '-', $subject) ?? $subject;
        $subject = preg_replace('/[^\p{L}\p{N}-]/u', '', $subject) ?? $subject;

        return trim(preg_replace('/-+/u', '-', $subject) ?? $subject, '-');
    }
}
