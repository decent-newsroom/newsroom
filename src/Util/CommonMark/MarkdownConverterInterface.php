<?php

namespace App\Util\CommonMark;

/**
 * Interface for markdown to HTML conversion
 */
interface MarkdownConverterInterface
{
    /**
     * Convert markdown/asciidoc content to HTML
     *
     * @param string $content Content to convert
     * @param string|null $format Optional format specification ('markdown', 'asciidoc', or null for auto-detect)
     * @param array|null $tags Optional Nostr event tags to seed bulk prefetch (each element: [tagName, value, ...])
     * @return string Converted HTML
     */
    public function convertToHTML(string $content, ?string $format = null, ?array $tags = null): string;
}
