<?php

declare(strict_types=1);

namespace App\Util\AsciiDoc;

/**
 * AsciiDoc to HTML Converter
 * Converts common AsciiDoc syntax to HTML
 */
class AsciiDocConverter
{
    /**
     * Convert AsciiDoc content to HTML
     */
    public function convert(string $asciidoc): string
    {
        // Normalize line endings
        $asciidoc = str_replace(["\r\n", "\r"], "\n", $asciidoc);

        $lines = explode("\n", $asciidoc);
        $html = '';
        $inBlockQuote = false;
        $inLiteralBlock = false;
        $inSidebarBlock = false;
        $inAdmonitionBlock = false;
        $admonitionType = null;
        $blockQuoteContent = [];
        $literalContent = [];
        $sidebarContent = [];
        $admonitionContent = [];
        $blockTitle = null; // Store block title for next block
        $i = 0;

        while ($i < count($lines)) {
            $line = $lines[$i];
            $trimmed = trim($line);

            // Check for admonition block start [NOTE], [TIP], [WARNING], [IMPORTANT], [CAUTION]
            if (preg_match('/^\[(NOTE|TIP|WARNING|IMPORTANT|CAUTION)\]$/i', $trimmed, $admonMatch)) {
                $admonitionType = strtoupper($admonMatch[1]);
                $i++;
                // Check if next line is empty (skip it)
                if ($i < count($lines) && trim($lines[$i]) === '') {
                    $i++;
                }
                // Check if next line is ==== delimiter
                if ($i < count($lines) && trim($lines[$i]) === '====') {
                    $inAdmonitionBlock = true;
                    $i++;
                }
                continue;
            }

            // Handle ==== delimiter (for admonition blocks)
            if ($trimmed === '====') {
                if ($inAdmonitionBlock) {
                    // End admonition block - process content and check for attribution
                    $admonitionClass = strtolower($admonitionType);
                    $admonitionIcon = $this->getAdmonitionIcon($admonitionType);

                    // Separate attribution if present (check last non-empty item)
                    $attribution = null;

                    // Look for attribution from the end, skipping empty <br /> tags
                    for ($j = count($admonitionContent) - 1; $j >= 0; $j--) {
                        $item = trim($admonitionContent[$j]);
                        if ($item === '' || $item === '<br />') {
                            continue;
                        }

                        // Check if this is an attribution line
                        if (preg_match('/^--\s+(.+)$/', $item, $attrMatch)) {
                            $attribution = $attrMatch[1];
                            // Remove this item and any trailing <br />
                            array_splice($admonitionContent, $j);
                            break;
                        }

                        // Found non-empty, non-attribution content, stop looking
                        break;
                    }

                    $content = implode("\n", $admonitionContent);

                    $html .= '<div class="admonition admonition-' . $admonitionClass . '">';
                    $html .= '<div class="admonition-title">' . $admonitionIcon . ' ' . $admonitionType . '</div>';
                    $html .= '<div class="admonition-content">' . $content . '</div>';

                    if ($attribution) {
                        $html .= '<div class="admonition-attribution">— ' . htmlspecialchars($attribution) . '</div>';
                    }

                    $html .= '</div>' . "\n";
                    $admonitionContent = [];
                    $inAdmonitionBlock = false;
                    $admonitionType = null;
                } else {
                    // Could be start of an example block or other block, skip for now
                    $html .= $line . "\n";
                }
                $i++;
                continue;
            }

            // Handle block quote delimiters
            if ($trimmed === '____') {
                if ($inBlockQuote) {
                    // End block quote - process content with line breaks
                    $processedContent = [];
                    foreach ($blockQuoteContent as $line) {
                        if (trim($line) === '') {
                            $processedContent[] = '<br />';
                        } else {
                            $processedContent[] = $line . '<br />';
                        }
                    }

                    // Remove trailing <br />
                    if (!empty($processedContent)) {
                        $lastIndex = count($processedContent) - 1;
                        $processedContent[$lastIndex] = rtrim($processedContent[$lastIndex], '<br />');
                    }

                    $html .= '<blockquote>' . implode("\n", $processedContent) . '</blockquote>' . "\n";
                    $blockQuoteContent = [];
                    $inBlockQuote = false;
                } else {
                    // Start block quote
                    $inBlockQuote = true;
                }
                $i++;
                continue;
            }

            // Handle literal/listing block delimiters
            if ($trimmed === '----' || $trimmed === '....') {
                if ($inLiteralBlock) {
                    // End literal block
                    $html .= '<pre><code>' . htmlspecialchars(implode("\n", $literalContent)) . '</code></pre>' . "\n";
                    $literalContent = [];
                    $inLiteralBlock = false;
                } else {
                    // Start literal block
                    $inLiteralBlock = true;
                }
                $i++;
                continue;
            }

            // Handle sidebar block delimiters
            if ($trimmed === '****') {
                if ($inSidebarBlock) {
                    // End sidebar block
                    $html .= '<aside class="sidebar">' . implode("\n", $sidebarContent) . '</aside>' . "\n";
                    $sidebarContent = [];
                    $inSidebarBlock = false;
                } else {
                    // Start sidebar block
                    $inSidebarBlock = true;
                }
                $i++;
                continue;
            }

            // Collect content for blocks
            if ($inBlockQuote) {
                $blockQuoteContent[] = $this->processInlineMarkup($line);
                $i++;
                continue;
            }

            if ($inLiteralBlock) {
                $literalContent[] = $line;
                $i++;
                continue;
            }

            if ($inSidebarBlock) {
                $sidebarContent[] = $this->processInlineMarkup($line);
                $i++;
                continue;
            }

            if ($inAdmonitionBlock) {
                // Process the line for various content types
                $processedLine = $this->processAdmonitionLine($line, $lines, $i);
                if ($processedLine !== null) {
                    $admonitionContent[] = $processedLine;
                }
                $i++;
                continue;
            }

            // Skip empty lines
            if ($trimmed === '') {
                $html .= "\n";
                $i++;
                continue;
            }

            // Check for block title (. followed by text, not a list item)
            if (preg_match('/^\.([^\s].*)$/', $trimmed, $titleMatch)) {
                // Make sure it's not a list item (which would have space after the dot)
                $blockTitle = trim($titleMatch[1]);
                $i++;
                continue;
            }

            // Check for attribute list (e.g., [.text-center])
            $attributes = [];
            if (preg_match('/^\[\.([^\]]+)\]$/', $trimmed, $attrMatch)) {
                $attributes['class'] = str_replace('.', ' ', $attrMatch[1]);
                $i++;
                if ($i >= count($lines)) break;
                $line = $lines[$i];
                $trimmed = trim($line);
            }

            // Check for [quote] or [quote, Author, Source] block
            if (preg_match('/^\[quote(?:,\s*([^,\]]+))?(?:,\s*([^\]]+))?\]$/i', $trimmed, $quoteMatch)) {
                $quoteAuthor = isset($quoteMatch[1]) ? trim($quoteMatch[1]) : null;
                $quoteSource = isset($quoteMatch[2]) ? trim($quoteMatch[2]) : null;

                $i++;
                if ($i >= count($lines)) break;

                // Skip empty line after [quote] if present
                if (trim($lines[$i]) === '') {
                    $i++;
                }

                if ($i >= count($lines)) break;

                // Check if next line is a delimiter (____) for delimited quote block
                if (trim($lines[$i]) === '____') {
                    // Delimited quote block - collect content until closing ____
                    $i++; // Skip opening delimiter
                    $quoteContent = [];

                    while ($i < count($lines) && trim($lines[$i]) !== '____') {
                        $line = $lines[$i];
                        if (trim($line) === '') {
                            // Empty line - preserve as line break
                            $quoteContent[] = '<br />';
                        } else {
                            // Process inline markup and preserve line
                            $quoteContent[] = $this->processInlineMarkup($line) . '<br />';
                        }
                        $i++;
                    }

                    if ($i < count($lines)) {
                        $i++; // Skip closing delimiter
                    }

                    // Remove trailing <br /> if present
                    if (!empty($quoteContent)) {
                        $lastIndex = count($quoteContent) - 1;
                        $quoteContent[$lastIndex] = rtrim($quoteContent[$lastIndex], '<br />');
                    }

                    $html .= '<blockquote>' . implode("\n", $quoteContent);

                    // Add attribution if author or source provided
                    if ($quoteAuthor || $quoteSource) {
                        $html .= '<footer class="blockquote-footer">';
                        if ($quoteAuthor) {
                            $html .= htmlspecialchars($quoteAuthor);
                        }
                        if ($quoteSource) {
                            if ($quoteAuthor) {
                                $html .= ', ';
                            }
                            $html .= '<cite>' . htmlspecialchars($quoteSource) . '</cite>';
                        }
                        $html .= '</footer>';
                    }

                    $html .= '</blockquote>' . "\n";
                } else {
                    // Simple quote block - single line
                    $line = $lines[$i];
                    $html .= '<blockquote>' . $this->processInlineMarkup($line);

                    // Add attribution if author or source provided
                    if ($quoteAuthor || $quoteSource) {
                        $html .= '<footer class="blockquote-footer">';
                        if ($quoteAuthor) {
                            $html .= htmlspecialchars($quoteAuthor);
                        }
                        if ($quoteSource) {
                            if ($quoteAuthor) {
                                $html .= ', ';
                            }
                            $html .= '<cite>' . htmlspecialchars($quoteSource) . '</cite>';
                        }
                        $html .= '</footer>';
                    }

                    $html .= '</blockquote>' . "\n";
                    $i++;
                }
                continue;
            }

            // Document title (= Title)
            if (preg_match('/^=\s+(.+)$/', $trimmed, $match)) {
                $title = $this->processInlineMarkup($match[1]);
                $attrStr = $this->buildAttributeString($attributes);
                $html .= "<h1{$attrStr}>{$title}</h1>\n";
                $i++;
                continue;
            }

            // Section titles (== Section, === Subsection, etc.)
            if (preg_match('/^(={2,6})\s+(.+)$/', $trimmed, $match)) {
                $level = strlen($match[1]);
                $title = $this->processInlineMarkup($match[2]);
                $attrStr = $this->buildAttributeString($attributes);
                $html .= "<h{$level}{$attrStr}>{$title}</h{$level}>\n";
                $i++;
                continue;
            }

            // Unordered list items (*, **, ***)
            if (preg_match('/^(\*+)\s+(.+)$/', $trimmed, $match)) {
                $level = strlen($match[1]);
                $content = $this->processInlineMarkup($match[2]);
                $html .= str_repeat('  ', $level - 1) . "<li>{$content}</li>\n";
                $i++;
                continue;
            }

            // Ordered list items (., .., ...)
            if (preg_match('/^(\.+)\s+(.+)$/', $trimmed, $match)) {
                $level = strlen($match[1]);
                $content = $this->processInlineMarkup($match[2]);
                $html .= str_repeat('  ', $level - 1) . "<li>{$content}</li>\n";
                $i++;
                continue;
            }

            // Horizontal rule
            if (preg_match('/^[\'\-_*]{3,}$/', $trimmed)) {
                $html .= "<hr />\n";
                $i++;
                continue;
            }

            // Image (image::path[alt text, width, align="center"])
            if (preg_match('/^image::([^\[]+)\[([^\]]*)\]$/', $trimmed, $match)) {
                $src = trim($match[1]);
                $attributesStr = trim($match[2]);

                $imgAttrs = $this->parseImageAttributes($attributesStr);
                $attrStr = $this->buildAttributeString($attributes);

                $styleAttr = $imgAttrs['style'] ? ' style="' . htmlspecialchars($imgAttrs['style']) . '"' : '';
                $alt = htmlspecialchars($imgAttrs['alt']);

                // If block title is present, wrap in figure with caption
                if ($blockTitle) {
                    $html .= '<figure' . $attrStr . '>' . "\n";
                    $html .= "  <img src=\"{$src}\" alt=\"{$alt}\"{$styleAttr} />\n";
                    $html .= '  <figcaption>' . htmlspecialchars($blockTitle) . '</figcaption>' . "\n";
                    $html .= '</figure>' . "\n";
                    $blockTitle = null; // Reset after use
                } else {
                    $html .= "<img src=\"{$src}\" alt=\"{$alt}\"{$attrStr}{$styleAttr} />\n";
                }

                $i++;
                continue;
            }

            // Audio (audio::path[description])
            if (preg_match('/^audio::([^\[]+)\[([^\]]*)\]$/', $trimmed, $match)) {
                $src = trim($match[1]);
                $description = trim($match[2]);
                $attrStr = $this->buildAttributeString($attributes);

                $html .= '<figure class="audio-block"' . $attrStr . '>' . "\n";

                // Use block title if present, otherwise use description from brackets
                $caption = $blockTitle ?: $description;

                if ($caption) {
                    $html .= '  <figcaption>' . htmlspecialchars($caption) . '</figcaption>' . "\n";
                }

                $html .= '  <audio controls preload="metadata">' . "\n";
                $html .= '    <source src="' . htmlspecialchars($src) . '">' . "\n";
                $html .= '    Your browser does not support the audio element.' . "\n";
                $html .= '  </audio>' . "\n";
                $html .= '</figure>' . "\n";

                $blockTitle = null; // Reset block title after use
                $i++;
                continue;
            }

            // Link (https://example.com[Link Text])
            if (preg_match('/^(https?:\/\/[^\[]+)\[([^\]]*)\]$/', $trimmed, $match)) {
                $url = trim($match[1]);
                $text = trim($match[2]) ?: $url;
                $html .= "<p><a href=\"{$url}\">{$text}</a></p>\n";
                $i++;
                continue;
            }

            // Regular paragraph
            $content = $this->processInlineMarkup($line);
            $attrStr = $this->buildAttributeString($attributes);
            $html .= "<p{$attrStr}>{$content}</p>\n";
            $i++;
        }

        // Close any unclosed blocks
        if ($inBlockQuote && !empty($blockQuoteContent)) {
            // Process with line breaks
            $processedContent = [];
            foreach ($blockQuoteContent as $line) {
                if (trim($line) === '') {
                    $processedContent[] = '<br />';
                } else {
                    $processedContent[] = $line . '<br />';
                }
            }

            // Remove trailing <br />
            if (!empty($processedContent)) {
                $lastIndex = count($processedContent) - 1;
                $processedContent[$lastIndex] = rtrim($processedContent[$lastIndex], '<br />');
            }

            $html .= '<blockquote>' . implode("\n", $processedContent) . '</blockquote>' . "\n";
        }
        if ($inLiteralBlock && !empty($literalContent)) {
            $html .= '<pre><code>' . htmlspecialchars(implode("\n", $literalContent)) . '</code></pre>' . "\n";
        }
        if ($inSidebarBlock && !empty($sidebarContent)) {
            $html .= '<aside class="sidebar">' . implode("\n", $sidebarContent) . '</aside>' . "\n";
        }
        if ($inAdmonitionBlock && !empty($admonitionContent)) {
            $admonitionClass = strtolower($admonitionType);
            $admonitionIcon = $this->getAdmonitionIcon($admonitionType);

            // Separate attribution if present
            $attribution = null;

            // Look for attribution from the end, skipping empty <br /> tags
            for ($j = count($admonitionContent) - 1; $j >= 0; $j--) {
                $item = trim($admonitionContent[$j]);
                if ($item === '' || $item === '<br />') {
                    continue;
                }

                // Check if this is an attribution line
                if (preg_match('/^--\s+(.+)$/', $item, $attrMatch)) {
                    $attribution = $attrMatch[1];
                    // Remove this item and any trailing <br />
                    array_splice($admonitionContent, $j);
                    break;
                }

                // Found non-empty, non-attribution content, stop looking
                break;
            }

            $content = implode("\n", $admonitionContent);

            $html .= '<div class="admonition admonition-' . $admonitionClass . '">';
            $html .= '<div class="admonition-title">' . $admonitionIcon . ' ' . $admonitionType . '</div>';
            $html .= '<div class="admonition-content">' . $content . '</div>';

            if ($attribution) {
                $html .= '<div class="admonition-attribution">— ' . htmlspecialchars($attribution) . '</div>';
            }

            $html .= '</div>' . "\n";
        }

        return $html;
    }

    /**
     * Process inline markup (bold, italic, code, etc.)
     */
    private function processInlineMarkup(string $text): string
    {
        // Escape HTML first
        $text = htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Bold (*text* or **text**)
        $text = preg_replace('/\*\*([^\*]+)\*\*/', '<strong>$1</strong>', $text);
        $text = preg_replace('/(?<!\*)\*([^\*\s][^\*]*[^\*\s]|\S)\*(?!\*)/', '<strong>$1</strong>', $text);

        // Italic (_text_ or __text__)
        $text = preg_replace('/__([^_]+)__/', '<em>$1</em>', $text);
        $text = preg_replace('/(?<!_)_([^_\s][^_]*[^_\s]|\S)_(?!_)/', '<em>$1</em>', $text);

        // Monospace (`text`)
        $text = preg_replace('/`([^`]+)`/', '<code>$1</code>', $text);

        // Superscript (^text^)
        $text = preg_replace('/\^([^\^]+)\^/', '<sup>$1</sup>', $text);

        // Subscript (~text~)
        $text = preg_replace('/~([^~]+)~/', '<sub>$1</sub>', $text);

        // Line breaks (trailing +)
        $text = preg_replace('/\s+\+$/', '<br />', $text);

        // Inline links (http://example.com[text])
        $text = preg_replace_callback(
            '/(https?:\/\/[^\[]+)\[([^\]]*)\]/',
            function($matches) {
                $url = $matches[1];
                $text = $matches[2] ?: $url;
                return '<a href="' . $url . '">' . $text . '</a>';
            },
            $text
        );

        // Bare URLs
        $text = preg_replace(
            '/(?<!href=")(https?:\/\/[^\s<>\[\]]+)/',
            '<a href="$1">$1</a>',
            $text
        );

        return $text;
    }

    /**
     * Build HTML attribute string from attributes array
     */
    private function buildAttributeString(array $attributes): string
    {
        if (empty($attributes)) {
            return '';
        }

        $parts = [];
        foreach ($attributes as $key => $value) {
            $parts[] = $key . '="' . htmlspecialchars($value) . '"';
        }

        return ' ' . implode(' ', $parts);
    }

    /**
     * Get icon/symbol for admonition type
     */
    private function getAdmonitionIcon(string $type): string
    {
        return match($type) {
            'NOTE' => 'ℹ️',
            'TIP' => '💡',
            'WARNING' => '⚠️',
            'IMPORTANT' => '❗',
            'CAUTION' => '🔥',
            default => '📌',
        };
    }

    /**
     * Parse image attributes from AsciiDoc image syntax
     * Format: image::src[alt text, width, key="value"]
     * Returns: ['alt' => string, 'width' => int|null, 'align' => string|null, 'style' => string]
     */
    private function parseImageAttributes(string $attributesString): array
    {
        $result = [
            'alt' => '',
            'width' => null,
            'align' => null,
            'style' => '',
        ];

        if (empty($attributesString)) {
            return $result;
        }

        // Split by comma, but respect quoted values
        $parts = [];
        $current = '';
        $inQuotes = false;
        $quoteChar = null;

        for ($i = 0; $i < strlen($attributesString); $i++) {
            $char = $attributesString[$i];

            if (($char === '"' || $char === "'") && ($i === 0 || $attributesString[$i - 1] !== '\\')) {
                if (!$inQuotes) {
                    $inQuotes = true;
                    $quoteChar = $char;
                } elseif ($char === $quoteChar) {
                    $inQuotes = false;
                    $quoteChar = null;
                }
                $current .= $char;
            } elseif ($char === ',' && !$inQuotes) {
                $parts[] = trim($current);
                $current = '';
            } else {
                $current .= $char;
            }
        }

        if ($current !== '') {
            $parts[] = trim($current);
        }

        // First part is alt text (unless it's a key=value pair)
        if (isset($parts[0]) && !str_contains($parts[0], '=')) {
            $result['alt'] = $parts[0];
            array_shift($parts);
        }

        // Second part might be width (if numeric)
        if (isset($parts[0]) && is_numeric($parts[0])) {
            $result['width'] = (int)$parts[0];
            array_shift($parts);
        }

        // Remaining parts are key=value pairs
        foreach ($parts as $part) {
            if (str_contains($part, '=')) {
                [$key, $value] = explode('=', $part, 2);
                $key = trim($key);
                $value = trim($value, ' "\'');

                if ($key === 'align') {
                    $result['align'] = $value;
                } elseif ($key === 'width' && is_numeric($value)) {
                    $result['width'] = (int)$value;
                }
            }
        }

        // Build style string
        $styles = [];
        if ($result['width']) {
            $styles[] = 'max-width: ' . $result['width'] . 'px';
        }
        if ($result['align']) {
            if ($result['align'] === 'center') {
                $styles[] = 'display: block';
                $styles[] = 'margin-left: auto';
                $styles[] = 'margin-right: auto';
            } elseif ($result['align'] === 'left') {
                $styles[] = 'float: left';
                $styles[] = 'margin-right: 1rem';
            } elseif ($result['align'] === 'right') {
                $styles[] = 'float: right';
                $styles[] = 'margin-left: 1rem';
            }
        }

        $result['style'] = implode('; ', $styles);

        return $result;
    }

    /**
     * Process a line within an admonition block
     * Handles images, links, lists, and other inline content
     */
    private function processAdmonitionLine(string $line, array $lines, int &$index): ?string
    {
        $trimmed = trim($line);

        // Skip empty lines (but preserve them)
        if ($trimmed === '') {
            return '<br />';
        }

        // Image (image::path[alt text, width, align="center"])
        if (preg_match('/^image::([^\[]+)\[([^\]]*)\]$/', $trimmed, $match)) {
            $src = trim($match[1]);
            $attributesStr = trim($match[2]);

            $imgAttrs = $this->parseImageAttributes($attributesStr);
            $styleAttr = $imgAttrs['style'] ? ' style="' . htmlspecialchars($imgAttrs['style']) . '"' : '';
            $alt = htmlspecialchars($imgAttrs['alt']);

            return '<img src="' . htmlspecialchars($src) . '" alt="' . $alt . '" class="admonition-image"' . $styleAttr . ' />';
        }

        // Audio (audio::path[description])
        if (preg_match('/^audio::([^\[]+)\[([^\]]*)\]$/', $trimmed, $match)) {
            $src = trim($match[1]);
            $description = trim($match[2]);

            $html = '<figure class="audio-block admonition-audio">';
            $html .= '<audio controls preload="metadata">';
            $html .= '<source src="' . htmlspecialchars($src) . '">';
            $html .= 'Your browser does not support the audio element.';
            $html .= '</audio>';
            if ($description) {
                $html .= '<figcaption>' . htmlspecialchars($description) . '</figcaption>';
            }
            $html .= '</figure>';

            return $html;
        }

        // Unordered list item
        if (preg_match('/^(\*+)\s+(.+)$/', $trimmed, $match)) {
            $content = $this->processInlineMarkup($match[2]);
            return '<li>' . $content . '</li>';
        }

        // Ordered list item
        if (preg_match('/^(\.+)\s+(.+)$/', $trimmed, $match)) {
            $content = $this->processInlineMarkup($match[2]);
            return '<li>' . $content . '</li>';
        }

        // Links (http://example.com[text])
        if (preg_match('/^(https?:\/\/[^\[]+)\[([^\]]*)\]$/', $trimmed, $match)) {
            $url = trim($match[1]);
            $text = trim($match[2]) ?: $url;
            return '<p><a href="' . htmlspecialchars($url) . '">' . htmlspecialchars($text) . '</a></p>';
        }

        // Attribution line (-- Author) - return raw for later processing
        if (preg_match('/^--\s+(.+)$/', $trimmed)) {
            return $trimmed;
        }

        // Regular paragraph with inline markup
        $content = $this->processInlineMarkup($line);
        return '<p>' . $content . '</p>';
    }
}

