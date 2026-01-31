<?php

namespace App\Util\CommonMark;

/**
 * Interface for markdown to HTML conversion
 */
interface MarkdownConverterInterface
{
    /**
     * Convert markdown content to HTML
     */
    public function convertToHTML(string $markdown): string;
}
