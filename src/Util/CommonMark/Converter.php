<?php

namespace App\Util\CommonMark;

use App\Enum\KindsEnum;
use App\Factory\ArticleFactory;
use App\Repository\ArticleRepository;
use App\Repository\EventRepository;
use App\Service\Cache\RedisCacheService;
use App\Util\AsciiDoc\AsciiDocConverter;
use App\Util\CommonMark\ImagesExtension\RawImageLinkExtension;
use App\Util\CommonMark\NostrSchemeExtension\NostrPrefetchedData;
use App\Util\CommonMark\NostrSchemeExtension\NostrSchemeExtension;
use App\Util\NostrKeyUtil;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Exception\CommonMarkException;
use League\CommonMark\Extension\Autolink\AutolinkExtension;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\Embed\Bridge\OscaroteroEmbedAdapter;
use League\CommonMark\Extension\Embed\Embed;
use League\CommonMark\Extension\Embed\EmbedExtension;
use League\CommonMark\Extension\Embed\EmbedRenderer;
use League\CommonMark\Extension\Footnote\FootnoteExtension;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkExtension;
use League\CommonMark\Extension\SmartPunct\SmartPunctExtension;
use League\CommonMark\Extension\Strikethrough\StrikethroughExtension;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\Extension\TableOfContents\TableOfContentsExtension;
use League\CommonMark\MarkdownConverter;
use League\CommonMark\Renderer\HtmlDecorator;
use nostriphant\NIP19\Bech32;
use nostriphant\NIP19\Data\NAddr;
use nostriphant\NIP19\Data\NEvent;
use nostriphant\NIP19\Data\Note;
use nostriphant\NIP19\Data\NProfile;
use Twig\Environment as TwigEnvironment;

class Converter implements MarkdownConverterInterface
{
    /** Match any nostr:* bech link (used for batching) */
    private const RE_ALL_NOSTR = '~nostr:(?:npub1|nprofile1|note1|nevent1|naddr1)[^\s<>()\[\]{}"\'`.,;:!?]*~i';

    /** Replace anchors with href="nostr:..." while preserving inner text */
    private const RE_NOSTR_ANCHOR = '~<a\b(?<attrs>[^>]*?)\bhref=(["\'])(?<nostr>nostr:(?:npub1|nprofile1|note1|nevent1|naddr1)[^"\']*)\2(?<attrs2>[^>]*)>(?<inner>.*?)</a>~is';

    /** Bare-text nostr links, defensive against href immediate prefix */
    private const RE_BARE_NOSTR = '~(?<!href=")(?<!href=\')nostr:(?:npub1|nprofile1|note1|nevent1|naddr1)[^\s<>()\[\]{}"\'`.,;:!?]*~i';

    /** Holds the pre-fetched data for the current conversion run. */
    private ?NostrPrefetchedData $prefetchedData = null;

    /** Math placeholders surviving CommonMark: token → final HTML math expression. */
    private array $mathPlaceholders = [];

    public function __construct(
        private readonly RedisCacheService $redisCacheService,
        private readonly TwigEnvironment $twig,
        private readonly NostrKeyUtil $nostrKeyUtil,
        private readonly ArticleFactory $articleFactory,
        private readonly ArticleRepository $articleRepository,
        private readonly AsciiDocConverter $asciidocConverter,
        private readonly EventRepository $eventRepository,
    ) {}


    /**
     * @param string      $content The raw content to convert
     * @param string|null $format  Optional format specification ('markdown', 'asciidoc', or null for auto-detect)
     * @param int|null    $kind    Optional Nostr event kind used for deterministic format mapping
     * @param array|null  $tags    Optional Nostr event tags (e.g. from raw JSON) to seed the prefetch
     * @throws CommonMarkException
     */
    public function convertToHTML(string $content, ?string $format = null, ?int $kind = null, ?array $tags = null): string
    {
        // Normalize bare bech32 entities (nevent1..., naddr1..., etc.) to nostr: URI
        // form so that all downstream parsers recognise them.
        $content = $this->normalizeBareNostrEntities($content);

        // Normalize Nostr math markup conventions: unwrap backtick-protected
        // math (`$…$`, `$$…$$`) and convert ```latex/math/tex code blocks
        // to display math before any further processing.
        $content = $this->normalizeNostrMathMarkup($content);

        // Extract all math expressions ($$…$$, $…$, \(…\), \[…\]) from the
        // raw content and replace with inert alphanumeric placeholders that
        // survive CommonMark unchanged. The actual math is stored in
        // $this->mathPlaceholders and restored after markdown conversion.
        $content = $this->extractMathPlaceholders($content);

        // Bulk prefetch all referenced nostr entities before any conversion
        $this->prefetchedData = $this->prefetchNostrData($content, $tags);

        $resolvedFormat = $format ?? $this->resolveFormatByKind($kind);

        try {
            // Explicit format (or kind mapping) always wins over heuristic detection.
            if ($resolvedFormat === 'asciidoc') {
                $html = $this->asciidocConverter->convert($content);
                $html = $this->restoreMathPlaceholders($html);
                return $this->processNostrLinks($html);
            }

            if ($resolvedFormat === 'markdown') {
                return $this->convertMarkdownToHTML($content);
            }

            // Otherwise, auto-detect if content is AsciiDoc or Markdown
            if ($this->isAsciiDoc($content)) {
                $html = $this->asciidocConverter->convert($content);
                $html = $this->restoreMathPlaceholders($html);
                return $this->processNostrLinks($html);
            }

            // Default to Markdown
            return $this->convertMarkdownToHTML($content);
        } finally {
            // Clear per-run state so no stale data leaks between calls.
            $this->prefetchedData = null;
            $this->mathPlaceholders = [];
        }
    }

    /**
     * Convert AsciiDoc content to HTML (forces AsciiDoc parser)
     * @throws CommonMarkException
     */
    public function convertAsciiDocToHTML(string $content): string
    {
        return $this->convertToHTML($content, 'asciidoc');
    }

    /**
     * Resolve parser format from event kind when callers don't pass an explicit format.
     */
    private function resolveFormatByKind(?int $kind): ?string
    {
        return match ($kind) {
            KindsEnum::LONGFORM->value,
            KindsEnum::LONGFORM_DRAFT->value => 'markdown',
            default => null,
        };
    }

    /**
     * Normalize Nostr math markup conventions in raw content.
     *
     * Many Nostr clients wrap math in backticks to protect it from markdown
     * parsers that don't understand math, and use ```latex/math/tex fenced
     * code blocks for display math.  This method:
     *
     *  1. Converts ```latex / ```math / ```tex fenced code blocks (including
     *     unclosed ones) into $$…$$ display math.
     *  2. Unwraps backtick-protected display math: `$$…$$` → $$…$$
     *  3. Unwraps backtick-protected inline math:  `$…$`  → $…$
     *
     * Runs before extractMathPlaceholders() and CommonMark.
     */
    private function normalizeNostrMathMarkup(string $content): string
    {
        // 1a. Convert properly closed ```latex / ```math / ```tex fenced code
        //     blocks to display math.
        $content = preg_replace_callback(
            '/^(`{3,})\s*(?:latex|math|tex)\s*$\n(.*?)^\1\s*$/ms',
            static function (array $m): string {
                $inner = trim($m[2]);
                if ($inner === '') {
                    return '';
                }
                // If the block already contains $$…$$ delimiters, strip the
                // fence and output as-is so that extractMathPlaceholders()
                // can handle each $$…$$ section individually (with prose
                // between them remaining as prose).
                if (str_contains($inner, '$$')) {
                    return "\n" . $inner . "\n";
                }
                return "\n$$\n" . $inner . "\n$$\n";
            },
            $content,
        ) ?? $content;

        // 1b. Handle remaining (unclosed) ```latex / ```math / ```tex opening
        //     fences: just remove the fence line.  The content after it will be
        //     processed naturally — any $$…$$ blocks are extracted as math and
        //     prose stays as prose.  This avoids eating everything to EOF.
        $content = preg_replace(
            '/^`{3,}\s*(?:latex|math|tex)\s*$/m',
            '',
            $content,
        ) ?? $content;

        // 1c. Normalize escaped backticks in inline code spans.
        //     Some Nostr clients use \` to escape backticks inside inline code:
        //       `const price = \`$${amount}\``
        //     CommonMark doesn't support \` in code spans — the \` terminates the
        //     span, leaving $${amount} exposed as regular text (and potential math).
        //     Convert to double-backtick syntax with literal inner backticks:
        //       `` const price = `$${amount}` ``
        //     (CommonMark strips one leading+trailing space from code span content.)
        //     Fenced code blocks are protected from this conversion.
        $fenced = [];
        $tmp = preg_replace_callback(
            '/^(`{3,}|~{3,})[^\n]*\n.*?^\1\s*$/ms',
            static function (array $m) use (&$fenced): string {
                $fenced[] = $m[0];
                return "\x00ESCBK" . (count($fenced) - 1) . "\x00";
            },
            $content,
        ) ?? $content;
        $tmp = preg_replace_callback(
            '/^(`{3,}|~{3,})[^\n]*\n.*\z/ms',
            static function (array $m) use (&$fenced): string {
                $fenced[] = $m[0];
                return "\x00ESCBK" . (count($fenced) - 1) . "\x00";
            },
            $tmp,
        ) ?? $tmp;
        $tmp = preg_replace_callback(
            '/(?<!\\\\)`((?:[^`\\\\\n]|\\\\[^\n])+)`/',
            static function (array $m): string {
                if (!str_contains($m[1], '\\`')) {
                    return $m[0]; // no escaped backticks — leave as-is
                }
                $inner = str_replace('\\`', '`', $m[1]);
                return '`` ' . $inner . ' ``';
            },
            $tmp,
        ) ?? $tmp;
        if (!empty($fenced)) {
            $tmp = preg_replace_callback(
                '/\x00ESCBK(\d+)\x00/',
                static fn(array $m) => $fenced[(int) $m[1]],
                $tmp,
            ) ?? $tmp;
        }
        $content = $tmp;

        // 2. Unwrap backtick-protected display math: `$$…$$` → $$…$$
        //    No /s flag — backtick inline code is single-line only; allowing
        //    `.` to cross newlines would let a `$$` on one line pair with a
        //    distant `$$` and swallow all content between them.
        $content = preg_replace(
            '/`(\$\$.+?\$\$)`/',
            '$1',
            $content,
        ) ?? $content;

        // 3. Unwrap backtick-protected inline math: `$…$` → $…$
        //    Only unwrap when the content between $ signs looks like math
        //    (contains LaTeX commands, ^, _, {, }) to avoid unwrapping shell
        //    variables like `$HOME` or CSS like `$primary-color`.
        $content = preg_replace_callback(
            '/`(\$[^$`]+\$)`/',
            static function (array $m): string {
                $inner = substr($m[1], 1, -1); // content between the $ signs
                // Only unwrap if it looks like math
                if (preg_match('/\\\\[a-zA-Z]|[\^_{}]/', $inner)) {
                    return $m[1];
                }
                // Also unwrap simple expressions like E=mc^2 — anything with = and a letter
                if (preg_match('/[a-zA-Z].*=|=.*[a-zA-Z]/', $inner)) {
                    return $m[1];
                }
                return $m[0]; // not math, keep the backticks
            },
            $content,
        ) ?? $content;

        return $content;
    }

    /**
     * Extract all math expressions from raw content, replacing them with
     * inert alphanumeric placeholders that CommonMark will pass through
     * unchanged (no backslash escaping, no special char processing).
     *
     * The math content is stored in $this->mathPlaceholders keyed by the
     * placeholder token.  Call restoreMathPlaceholders() on the HTML output
     * after CommonMark conversion to re-inject the actual math.
     *
     * Replaces the old normalizeInlineMathDelimiters() which converted
     * $…$ → \(…\) in raw markdown.  That approach was broken because
     * CommonMark treats \( as an escaped ( and strips the backslash.
     */
    private function extractMathPlaceholders(string $content): string
    {
        $hasDollar     = str_contains($content, '$');
        $hasBackslash  = str_contains($content, '\\');
        if (!$hasDollar && !$hasBackslash) {
            return $content;
        }

        $this->mathPlaceholders = [];
        $counter = 0;

        // Helper: store math and return an inert placeholder token.
        $placeholder = function (string $mathHtml) use (&$counter): string {
            $token = 'MATHPH' . $counter . 'XEND';
            $this->mathPlaceholders[$token] = $mathHtml;
            $counter++;
            return $token;
        };

        // --- Protect non-math regions that contain $ or \ ---
        $codeBlocks = [];
        $protectCode = function (array $m) use (&$codeBlocks): string {
            $codeBlocks[] = $m[0];
            return "\x00CODEBLOCK" . (count($codeBlocks) - 1) . "\x00";
        };

        // 1. Fenced code blocks (``` or ~~~) — both closed and unclosed.
        //    Closed blocks: opening fence + content + matching closing fence.
        $content = preg_replace_callback('/^(`{3,}|~{3,})[^\n]*\n.*?^\1\s*$/ms', $protectCode, $content) ?? $content;
        //    Unclosed blocks: opening fence + everything to EOF.
        $content = preg_replace_callback('/^(`{3,}|~{3,})[^\n]*\n.*\z/ms', $protectCode, $content) ?? $content;

        // 2. Multi-backtick inline code spans (`` `…` ``, ``` ``…`` ```, etc.)
        //    Must come before single-backtick to avoid partial matches.
        $content = preg_replace_callback('/(`{2,})(?!`)(.+?)(?<!`)\1(?!`)/s', $protectCode, $content) ?? $content;
        // 3. Single-backtick inline code
        $content = preg_replace_callback('/`[^`\n]+`/', $protectCode, $content) ?? $content;

        // --- Extract math regions in order of specificity ---

        // 3a. Display math $$…$$ on a single line.  This safely handles the
        //     vast majority of display math (e.g. $$E=mc^2$$, $$\frac{a}{b}$$)
        //     and avoids a lone $$ (like "Empty math: $$" or JS template
        //     `$${amount}`) pairing with a distant $$ across lines.  Multi-line
        //     display math is handled by step 3b which requires LaTeX markup.
        $content = preg_replace_callback(
            '/\$\$([^\n]+?)\$\$/',
            function (array $m) use ($placeholder): string {
                if (trim($m[1]) === '') {
                    return $m[0]; // empty display math — leave as literal $$
                }
                return $placeholder('$$' . $m[1] . '$$');
            },
            $content,
        ) ?? $content;

        // 3b. Multi-paragraph display math $$…$$ — only extract when the
        //     content between $$ delimiters contains LaTeX markup (commands,
        //     environments, etc.).  This handles blocks like \begin{cases}…
        //     \end{cases} that legitimately span blank lines.
        $content = preg_replace_callback(
            '/\$\$([\s\S]*?)\$\$/',
            function (array $m) use ($placeholder): string {
                $inner = $m[1];
                if (trim($inner) === '') {
                    return $m[0]; // empty — leave as-is
                }
                // Require LaTeX markup to accept a paragraph-spanning match
                if (preg_match('/\\\\[a-zA-Z]|[\^_]|\\\\\\\\/', $inner)) {
                    return $placeholder('$$' . $inner . '$$');
                }
                return $m[0]; // prose, not math — leave as-is
            },
            $content,
        ) ?? $content;

        // 4. LaTeX display \[…\] → placeholder (preserve as \[…\])
        if ($hasBackslash) {
            $content = preg_replace_callback(
                '/\\\\\[([\s\S]*?)\\\\]/',
                function (array $m) use ($placeholder): string {
                    return $placeholder('\\[' . $m[1] . '\\]');
                },
                $content,
            ) ?? $content;

            // 5. LaTeX inline \(…\) → placeholder (preserve as \(…\))
            $content = preg_replace_callback(
                '/\\\\\((.+?)\\\\\\)/',
                function (array $m) use ($placeholder): string {
                    return $placeholder('\\(' . $m[1] . '\\)');
                },
                $content,
            ) ?? $content;
        }

        // 6. Inline $…$ with TeX whitespace rule → placeholder as \(…\)
        if ($hasDollar) {
            $content = preg_replace_callback(
                '/(?<![\\\\$\d])\$([^\s$](?:[^$]*?[^\s$])?)\$(?![\d$])/',
                function (array $m) use ($placeholder): string {
                    // Skip pure numeric / currency values
                    if (preg_match('/^\s*[\d.,]+\s*$/', $m[1])) {
                        return $m[0];
                    }
                    return $placeholder('\\(' . $m[1] . '\\)');
                },
                $content,
            ) ?? $content;
        }

        // --- Restore protected code blocks ---
        if (!empty($codeBlocks)) {
            $content = preg_replace_callback(
                '/\x00CODEBLOCK(\d+)\x00/',
                static fn(array $m) => $codeBlocks[(int) $m[1]],
                $content,
            ) ?? $content;
        }

        return $content;
    }

    /**
     * Restore math placeholders in HTML output after CommonMark/AsciiDoc
     * conversion.  Each MATHPHnXEND token is replaced with the original
     * math expression (delimiters intact) so KaTeX can render them.
     */
    private function restoreMathPlaceholders(string $html): string
    {
        if (empty($this->mathPlaceholders)) {
            return $html;
        }

        return str_replace(
            array_keys($this->mathPlaceholders),
            array_values($this->mathPlaceholders),
            $html,
        );
    }

    /**
     * Normalize bare bech32 Nostr entities (nevent1…, naddr1…, note1…,
     * nprofile1…, npub1…) that appear without a `nostr:` URI prefix.
     *
     * Many Nostr clients publish content with bare bech32 identifiers on
     * their own line.  The downstream CommonMark inline parsers and the
     * post-HTML processNostrLinks() phase both expect the `nostr:` prefix,
     * so we add it here early in the pipeline.
     *
     * Code blocks (fenced and inline) are protected so that identifiers
     * inside them are left untouched.
     */
    private function normalizeBareNostrEntities(string $content): string
    {
        // Quick bail-out when there is clearly nothing to normalise.
        if (!preg_match('/(?:nevent|naddr|note|nprofile|npub)1/', $content)) {
            return $content;
        }

        // --- Protect regions that must not be touched ---
        $blocks = [];
        $protect = function (array $m) use (&$blocks): string {
            $blocks[] = $m[0];
            return "\x00NOSTR_NORM" . (count($blocks) - 1) . "\x00";
        };

        // 1. Fenced code blocks (``` or ~~~)
        $content = preg_replace_callback('/^(`{3,}|~{3,}).*?^\1/ms', $protect, $content);
        // 2. Inline code (backtick runs)
        $content = preg_replace_callback('/`[^`]+`/', $protect, $content);

        // --- Add nostr: prefix to bare bech32 entities ---
        // Negative look-behind prevents double-prefixing (nostr:nevent1…) and
        // avoids matching inside URLs (…/nevent1…).
        $content = preg_replace(
            '~(?<!nostr:)(?<![a-zA-Z0-9/])(?=(?:nevent|naddr|note|nprofile|npub)1)((?:nevent|naddr|note|nprofile|npub)1[^\s<>()\[\]{}"\'`.,;:!?]+)~',
            'nostr:$1',
            $content
        ) ?? $content;

        // --- Restore protected blocks ---
        if (!empty($blocks)) {
            $content = preg_replace_callback(
                '/\x00NOSTR_NORM(\d+)\x00/',
                static fn(array $m) => $blocks[(int) $m[1]],
                $content,
            );
        }

        return $content;
    }

    /**
     * Detect if content is likely AsciiDoc
     */
    private function isAsciiDoc(string $content): bool
    {
        // Prefer Markdown when the document clearly uses Markdown constructs.
        // This prevents thematic breaks like ---- from forcing AsciiDoc mode,
        // which would render Markdown headings/link syntax as literal text.
        if ($this->looksLikeMarkdown($content)) {
            return false;
        }

        // Strong AsciiDoc-only signals.
        $asciidocPatterns = [
            '/^\[\.[\w\-]+\]$/m',                        // Attribute lists like [.text-center]
            '/^\[(NOTE|TIP|WARNING|IMPORTANT|CAUTION)\]$/mi', // Admonition block header
            '/^\[quote(?:,\s*[^,\]]+)?(?:,\s*[^\]]+)?\]$/mi', // Quote blocks
            '/^=\s+.+$/m',                                   // Document title (= Title)
            '/^={2,6}\s+.+$/m',                              // Section titles (== Title)
            '/^\.{2,}\s+/m',                                // Ordered list with dots
            '/^(?:image|audio)::/m',                          // Media macros
        ];

        foreach ($asciidocPatterns as $pattern) {
            if (preg_match($pattern, $content)) {
                return true;
            }
        }

        // Ambiguous AsciiDoc block delimiters should only count when they
        // appear as a real paired block, not as a single Markdown separator.
        foreach (['----', '....', '____', '****'] as $delimiter) {
            if ($this->hasPairedAsciiDocDelimiter($content, $delimiter)) {
                return true;
            }
        }

        return false;
    }

    private function looksLikeMarkdown(string $content): bool
    {
        $markdownPatterns = [
            '/^\s{0,3}#{1,6}\s+.+$/m',                      // ATX headings
            '/\[[^\]]+\]\([^\)]+\)/',                   // Inline links [text](url)
            '/^\s{0,3}\d+\.\s+.+$/m',                     // Ordered lists
            '/^\s{0,3}>\s+.+$/m',                           // Blockquotes
            '/^(`{3,}|~{3,})[^\n]*$/m',                      // Fenced code blocks
        ];

        foreach ($markdownPatterns as $pattern) {
            if (preg_match($pattern, $content)) {
                return true;
            }
        }

        return false;
    }

    private function hasPairedAsciiDocDelimiter(string $content, string $delimiter): bool
    {
        $pattern = '/^' . preg_quote($delimiter, '/') . '$/m';

        return preg_match_all($pattern, $content) >= 2;
    }

    /**
     * Normalize emphasis markers that contain interior whitespace.
     *
     * Many Nostr clients and authors leave spaces inside bold/italic markers
     * (e.g. "*text *" or "** text **").  The CommonMark spec requires emphasis
     * delimiters to be "flanking" — the opening delimiter must not be followed
     * by whitespace and the closing delimiter must not be preceded by whitespace.
     * This method trims those interior spaces so CommonMark can recognise them.
     *
     * Code blocks (fenced and inline) are protected from modification.
     * Bullet list markers (* item) are detected and only the content after the
     * marker is processed, so list structure is preserved.
     */
    private function normalizeEmphasisWhitespace(string $content): string
    {
        // Quick bail-out: no emphasis markers at all.
        if (!str_contains($content, '*') && !str_contains($content, '_')) {
            return $content;
        }

        // --- Protect code regions ---
        $codeBlocks = [];
        $protectCode = function (array $m) use (&$codeBlocks): string {
            $codeBlocks[] = $m[0];
            return "\x00EMPHWS" . (count($codeBlocks) - 1) . "\x00";
        };
        // Fenced code blocks (closed)
        $content = preg_replace_callback('/^(`{3,}|~{3,})[^\n]*\n.*?^\1\s*$/ms', $protectCode, $content) ?? $content;
        // Fenced code blocks (unclosed — to EOF)
        $content = preg_replace_callback('/^(`{3,}|~{3,})[^\n]*\n.*\z/ms', $protectCode, $content) ?? $content;
        // Multi-backtick inline code
        $content = preg_replace_callback('/(`{2,})(?!`)(.+?)(?<!`)\1(?!`)/s', $protectCode, $content) ?? $content;
        // Single-backtick inline code
        $content = preg_replace_callback('/`[^`\n]+`/', $protectCode, $content) ?? $content;

        // Relocate callback: moves interior leading/trailing whitespace to
        // the outside of the delimiter so that words never run together.
        // e.g. "** bold **" → " **bold** ", "*text *" → "*text* "
        $relocateEmphasis = static function (array $m, string $delim): string {
            $inner = $m[1];
            if (!preg_match('/^([ \t]*)(.*?)([ \t]*)$/s', $inner, $parts)) {
                return $m[0];
            }
            $leading  = $parts[1];
            $core     = $parts[2];
            $trailing = $parts[3];
            if ($core === '' || ($leading === '' && $trailing === '')) {
                return $m[0]; // nothing to relocate, or content is empty
            }
            return $leading . $delim . $core . $delim . $trailing;
        };

        // Process line-by-line to respect block structure (lists)
        $lines = explode("\n", $content);
        foreach ($lines as &$line) {
            // ** bold **: relocate interior whitespace (** is never a list marker)
            $line = preg_replace_callback(
                '/\*\*(.+?)\*\*/',
                static fn(array $m) => $relocateEmphasis($m, '**'),
                $line,
            ) ?? $line;

            // __ bold __: relocate interior whitespace
            $line = preg_replace_callback(
                '/__(.+?)__/',
                static fn(array $m) => $relocateEmphasis($m, '__'),
                $line,
            ) ?? $line;

            // _italic_: relocate interior whitespace (_ is never a list marker)
            $line = preg_replace_callback(
                '/(?<!_)_(?!_)(.+?)(?<!_)_(?!_)/',
                static fn(array $m) => $relocateEmphasis($m, '_'),
                $line,
            ) ?? $line;

            // *italic*: relocate interior whitespace, but protect list markers.
            // If the line starts with * as a bullet list marker, only process
            // emphasis in the content after the marker prefix.
            if (preg_match('/^\s*\*\s/', $line)) {
                if (preg_match('/^(\s*\*\s+)(.*)$/', $line, $parts)) {
                    $rest = preg_replace_callback(
                        '/(?<!\*)\*(?!\*)(.+?)(?<!\*)\*(?!\*)/',
                        static fn(array $m) => $relocateEmphasis($m, '*'),
                        $parts[2],
                    ) ?? $parts[2];
                    $line = $parts[1] . $rest;
                }
            } else {
                $line = preg_replace_callback(
                    '/(?<!\*)\*(?!\*)(.+?)(?<!\*)\*(?!\*)/',
                    static fn(array $m) => $relocateEmphasis($m, '*'),
                    $line,
                ) ?? $line;
            }
        }
        unset($line);
        $content = implode("\n", $lines);

        // --- Restore protected code blocks ---
        if (!empty($codeBlocks)) {
            $content = preg_replace_callback(
                '/\x00EMPHWS(\d+)\x00/',
                static fn(array $m) => $codeBlocks[(int) $m[1]],
                $content,
            ) ?? $content;
        }

        return $content;
    }

    /**
     * Convert Markdown to HTML (original method)
     * @throws CommonMarkException
     */
    private function convertMarkdownToHTML(string $markdown): string
    {
        // Decode HTML entities early so that constructs like &#x20; inside
        // emphasis markers are visible as real whitespace to the normaliser
        // (e.g. "*text&#x20;*" → "*text *" → " *text*").
        $markdown = html_entity_decode($markdown);

        // Normalize emphasis markers with interior whitespace so that
        // CommonMark recognises them as flanking delimiters.
        $markdown = $this->normalizeEmphasisWhitespace($markdown);

        $headingsCount = preg_match_all('/^#+\s.*$/m', $markdown);

        $config = [
            'table_of_contents' => ['min_heading_level' => 1, 'max_heading_level' => 3],
            'heading_permalink' => ['symbol' => '§'],
            'autolink'          => ['allowed_protocols' => ['https'], 'default_protocol' => 'https'],
            'embed'             => [
                'adapter'         => new OscaroteroEmbedAdapter(),
                'allowed_domains' => ['youtube.com', 'x.com', 'github.com', 'fountain.fm', 'blossom.primal.net', 'i.nostr.build', 'video.nostr.build'],
                'fallback'        => 'link',
            ],
        ];

        $env = new Environment($config);
        $env->addExtension(new CommonMarkCoreExtension());
        $env->addExtension(new FootnoteExtension());
        $env->addExtension(new TableExtension());
        $env->addExtension(new StrikethroughExtension());
        $env->addExtension(new SmartPunctExtension());
        $env->addExtension(new EmbedExtension());
        $env->addRenderer(Embed::class, new HtmlDecorator(new EmbedRenderer(), 'div', ['class' => 'embedded-content']));
        $env->addExtension(new NostrSchemeExtension($this->redisCacheService, $this->nostrKeyUtil, $this->prefetchedData));
        $env->addExtension(new RawImageLinkExtension());
        $env->addExtension(new AutolinkExtension());

        if ($headingsCount > 3) {
            $env->addExtension(new HeadingPermalinkExtension());
            $env->addExtension(new TableOfContentsExtension());
        }

        $converter = new MarkdownConverter($env);
        $html = (string) $converter->convert($markdown);

        // Restore math expressions that were extracted before CommonMark
        // processing.  The placeholders are pure alphanumeric tokens that
        // CommonMark passed through unchanged; now we inject the real math
        // (with \(, $$, \\, etc.) directly into the HTML where backslash
        // escaping is no longer a concern.
        $html = $this->restoreMathPlaceholders($html);

        return $this->processNostrLinks($html);
    }

    private function processNostrLinks(string $content): string
    {
        // 1) Collect all nostr refs for batching (anchors + bare text)
        preg_match_all(self::RE_ALL_NOSTR, $content, $mAll);
        if (empty($mAll[0])) {
            return $content;
        }

        $uniqueLinks = array_values(array_unique($mAll[0]));
        [$eventIds, $pubkeyHexes, $naddrCoords] = $this->collectBatchKeys($uniqueLinks);

        // 2) Reuse prefetched data, only fetch what's missing
        $eventsById   = $this->prefetchedData ? $this->prefetchedData->getAllEvents() : [];
        $metadataByHex = $this->prefetchedData ? $this->prefetchedData->getAllMetadata() : [];
        $eventsByNaddr = $this->prefetchedData ? $this->prefetchedData->getAllNaddrEvents() : [];

        // Fetch any event IDs not already prefetched
        $missingEventIds = array_diff_key($eventIds, $eventsById);
        if (!empty($missingEventIds)) {
            $extra = $this->fetchEventsById($missingEventIds, $pubkeyHexes);
            $eventsById = array_merge($eventsById, $extra);
        }

        // Fetch naddr coordinates not already prefetched
        $missingNaddrCoords = [];
        foreach ($naddrCoords as $bech => $coord) {
            $coordKey = $coord['kind'] . ':' . $coord['pubkey'] . ':' . $coord['identifier'];
            if (!isset($eventsByNaddr[$coordKey])) {
                $missingNaddrCoords[] = $coordKey;
            }
        }
        if (!empty($missingNaddrCoords)) {
            $extra = $this->fetchEventsByNaddr($missingNaddrCoords, $pubkeyHexes);
            $eventsByNaddr = array_merge($eventsByNaddr, $extra);
        }

        // Fetch any pubkey metadata not already prefetched
        $missingHexes = array_diff(array_keys($pubkeyHexes), array_keys($metadataByHex));
        if (!empty($missingHexes)) {
            $extra = $this->fetchMetadataByHex($missingHexes);
            $metadataByHex = array_merge($metadataByHex, $extra);
        }

        // 3) Replace anchors (inline by default, card if data-embed or class)
        $content = $this->replaceNostrAnchors($content, $eventsById, $metadataByHex, $eventsByNaddr);

        // 4) Replace bare text only in text nodes
        $content = $this->replaceBareTextNostr($content, $eventsById, $metadataByHex, $eventsByNaddr);

        return $content;
    }

    /** @return array{0: array<string,int>, 1: array<string,int>, 2: array<string,array>} [$eventIds, $pubkeyHexes, $naddrCoords] */
    private function collectBatchKeys(array $links): array
    {
        $eventIds = [];    // id => 1
        $pubkeyHexes = []; // hex => 1
        $naddrCoords = []; // bech => ['kind' => x, 'pubkey' => hex, 'identifier' => d, 'relays' => [...]]

        foreach ($links as $link) {
            $bech = substr($link, 6);
            try {
                $decoded = new Bech32($bech);
                switch ($decoded->type) {
                    case 'npub':
                        $hex = $this->nostrKeyUtil->npubToHex($bech);
                        $pubkeyHexes[$hex] = 1;
                        break;
                    case 'nprofile':
                        /** @var NProfile $obj */
                        $obj = $decoded->data;
                        $pubkeyHexes[$obj->pubkey] = 1;
                        break;
                    case 'note':
                        /** @var Note $obj */
                        $obj = $decoded->data;
                        $eventIds[$obj->data] = 1;
                        break;
                    case 'nevent':
                        /** @var NEvent $obj */
                        $obj = $decoded->data;
                        $eventIds[$obj->id] = 1;
                        break;
                    case 'naddr':
                        /** @var NAddr $obj */
                        $obj = $decoded->data;
                        $naddrCoords[$bech] = [
                            'kind' => $obj->kind,
                            'pubkey' => $obj->pubkey,
                            'identifier' => $obj->identifier,
                            'relays' => $obj->relays ?? []
                        ];
                        $pubkeyHexes[$obj->pubkey] = 1;
                        break;
                }
            } catch (\Throwable) {
                // skip invalid
            }
        }

        return [$eventIds, $pubkeyHexes, $naddrCoords];
    }

    /** @param array<string,int> $eventIds  @param array<string,int> $pubkeyHexes  @return array<string,object> */
    private function fetchEventsById(array $eventIds, array &$pubkeyHexes): array
    {
        $eventsById = [];
        if (empty($eventIds)) {
            return $eventsById;
        }

        // Resolve from local database only — unresolved references become
        // deferred embed placeholders that are resolved at template render
        // time by the resolve_nostr_embeds Twig filter.
        try {
            $dbEvents = $this->eventRepository->findByIds(array_keys($eventIds));
            foreach ($dbEvents as $id => $entity) {
                $obj = new \stdClass();
                $obj->id = $entity->getId();
                $obj->kind = $entity->getKind();
                $obj->pubkey = $entity->getPubkey();
                $obj->content = $entity->getContent();
                $obj->created_at = $entity->getCreatedAt();
                $obj->tags = $entity->getTags();
                $obj->sig = $entity->getSig();
                $eventsById[$id] = $obj;
                if (!empty($obj->pubkey)) {
                    $pubkeyHexes[$obj->pubkey] = 1;
                }
            }
        } catch (\Throwable) {
            // DB lookup failed; deferred embeds will handle resolution at render time
        }

        return $eventsById;
    }

    /** @param string[] $hexes  @return array<string, mixed|null> */
    private function fetchMetadataByHex(array $hexes): array
    {
        if (empty($hexes)) {
            return [];
        }

        $byHex = [];
        try {
            $fetched = $this->redisCacheService->getMultipleMetadata($hexes);
            foreach ($hexes as $hex) {
                $byHex[$hex] = $fetched[$hex] ?? null;
            }
        } catch (\Throwable) {
            foreach ($hexes as $hex) {
                $byHex[$hex] = null;
            }
        }

        return $byHex;
    }

    /** Replace <a href="nostr:...">…</a> with inline links by default (card if opted in) */
    private function replaceNostrAnchors(string $content, array $eventsById, array $metadataByHex, array $eventsByNaddr = []): string
    {
        return preg_replace_callback(self::RE_NOSTR_ANCHOR, function ($m) use ($eventsById, $metadataByHex, $eventsByNaddr) {
            $nostrUrl = $m['nostr'];
            $bech     = substr($nostrUrl, 6);
            $attrsAll = trim(($m['attrs'] ?? '') . ' ' . ($m['attrs2'] ?? ''));
            $inner    = $m['inner'];

            // Inline by default for anchors
            $preferInline = true;

            // Opt-in to card if data-embed="1" or class contains "nostr-card" or "embed"
            if (preg_match('~\bdata-embed\s*=\s*("1"|\'1\'|1)\b~i', $attrsAll) ||
                preg_match('~\bclass\s*=\s*("|\')[^"\']*\b(nostr-card|embed)\b[^"\']*\1~i', $attrsAll)) {
                $preferInline = false;
            }

            try {
                $decoded = new Bech32($bech);
                return $this->renderNostrLink($decoded, $bech, $metadataByHex, $eventsById, $inner, $preferInline, $eventsByNaddr);
            } catch (\Throwable) {
                return $m[0]; // keep original anchor on error
            }
        }, $content);
    }

    /** Replace bare-text nostr links in text nodes only */
    private function replaceBareTextNostr(string $content, array $eventsById, array $metadataByHex, array $eventsByNaddr = []): string
    {
        $parts = preg_split('~(<[^>]+>)~', $content, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            return $content;
        }

        foreach ($parts as $i => $part) {
            // Skip tags and empties
            if ($part === '' || $part[0] === '<') {
                continue;
            }

            $parts[$i] = preg_replace_callback(self::RE_BARE_NOSTR, function ($mm) use ($eventsById, $metadataByHex, $eventsByNaddr) {
                $nostrUrl = $mm[0];
                $bech     = substr($nostrUrl, 6);
                try {
                    $decoded = new Bech32($bech);
                    // Bare text can render cards (preferInline = false)
                    return $this->renderNostrLink($decoded, $bech, $metadataByHex, $eventsById, null, false, $eventsByNaddr);
                } catch (\Throwable) {
                    return $nostrUrl;
                }
            }, $part);
        }

        return implode('', $parts);
    }

    /**
     * Renders a single nostr reference to HTML.
     * - $metadataByHex: authorHex => profile
     * - $eventsById: event.id => event
     * - $displayText: preserve original anchor text if provided
     * - $preferInline: true for inline <a>, false to allow cards
     * - $eventsByNaddr: "kind:pubkey:d-tag" => event (from naddr batch fetch)
     */
    private function renderNostrLink(
        Bech32 $decoded,
        string $bechEncoded,
        array $metadataByHex,
        array $eventsById,
        ?string $displayText = null,
        bool $preferInline = false,
        array $eventsByNaddr = [],
    ): string {
        switch ($decoded->type) {
            case 'npub': {
                try {
                    $hex = $this->nostrKeyUtil->npubToHex($bechEncoded);
                } catch (\Throwable) {
                    // Invalid bech32 checksum or malformed npub in content.
                    // Don't fail the whole article rendering/processing; just return the raw text.
                    return $this->e($bechEncoded);
                }
                 $profile = $metadataByHex[$hex] ?? null;
                 $label   = $displayText !== null && $displayText !== ''
                     ? $displayText
                     : (($profile->name ?? null) ?: $this->labelFromKey($bechEncoded));

                 return '<a href="/p/' . $this->e($bechEncoded) . '" class="nostr-mention">@' . $this->e($label) . '</a>';
            }

            case 'nprofile': {
                /** @var NProfile $obj */
                $obj     = $decoded->data;
                $hex     = $obj->pubkey;
                try {
                    $npub = $this->nostrKeyUtil->hexToNpub($hex);
                } catch (\Throwable) {
                    // Shouldn't happen, but keep processing resilient.
                    return $this->e($bechEncoded);
                }
                 $profile = $metadataByHex[$hex] ?? null;
                 $label   = $displayText !== null && $displayText !== ''
                     ? $displayText
                     : (($profile->name ?? null) ?: $this->labelFromKey($npub));

                 return '<a href="/p/' . $this->e($npub) . '" class="nostr-mention">@' . $this->e($label) . '</a>';
            }

            case 'note': {
                /** @var Note $obj */
                $obj   = $decoded->data;
                $event = $eventsById[$obj->data] ?? null;

                // Card only if allowed and kind 20 (picture)
                if (!$preferInline && $event && (int) $event->kind === 20) {
                    return $this->twig->render('/event/_kind20_picture.html.twig', [
                        'event' => $event,
                        'embed' => true,
                    ]);
                }

                // Deferred embed if event data is missing and we're not forced inline
                if (!$event && !$preferInline) {
                    return $this->renderDeferredEmbed($bechEncoded, 'note');
                }

                $text = $displayText !== null && $displayText !== '' ? $displayText : $bechEncoded;
                return '<a href="/e/' . $this->e($bechEncoded) . '" class="nostr-link">' . $this->e($text) . '</a>';
            }

            case 'nevent': {
                /** @var NEvent $obj */
                $obj   = $decoded->data;
                $event = $eventsById[$obj->id] ?? null;

                // Inline if requested (anchors)
                if ($preferInline) {
                    $text = $displayText !== null && $displayText !== '' ? $displayText : $bechEncoded;
                    return '<a href="/e/' . $this->e($bechEncoded) . '" class="nostr-link">' . $this->e($text) . '</a>';
                }

                // Deferred embed if event data is missing
                if (!$event) {
                    return $this->renderDeferredEmbed($bechEncoded, 'nevent');
                }

                // Otherwise render a rich card
                $authorMeta = $metadataByHex[$event->pubkey] ?? null;
                return $this->twig->render('components/event_card.html.twig', [
                    'event'  => $event,
                    'author' => $authorMeta,
                    'nevent' => $bechEncoded,
                ]);
            }

            case 'naddr': {
                /** @var NAddr $obj */
                $obj   = $decoded->data;
                $coordKey = $obj->kind . ':' . $obj->pubkey . ':' . $obj->identifier;
                $event = $eventsByNaddr[$coordKey] ?? null;

                $isLongform = (int) $obj->kind === (int) KindsEnum::LONGFORM->value;
                $href = $isLongform
                    ? '/article/' . $this->e($bechEncoded)
                    : '/e/' . $this->e($bechEncoded);

                // Inline if requested (anchors)
                if ($preferInline) {
                    $text = $displayText !== null && $displayText !== '' ? $displayText : $bechEncoded;
                    return '<a href="' . $href . '" class="nostr-link">' . $this->e($text) . '</a>';
                }

                // Deferred embed if event data is missing
                if (!$event) {
                    return $this->renderDeferredEmbed($bechEncoded, 'naddr');
                }

                // Otherwise render a rich card
                $authorMeta = $metadataByHex[$event->pubkey] ?? null;

                // Use article card for longform content (kind 30023)
                if ((int) $event->kind === (int) KindsEnum::LONGFORM->value) {
                    try {
                        // Convert event to Article entity for the Card component
                        $article = $this->articleFactory->createFromLongFormContentEvent($event);

                        // Prepare authors metadata in the format expected by Card component
                        $authorsMetadata = $authorMeta ? [$event->pubkey => $authorMeta] : [];

                        return $this->twig->render('components/Molecules/Card.html.twig', [
                            'article' => $article,
                            'authors_metadata' => $authorsMetadata,
                            'is_author_profile' => false,
                        ]);
                    } catch (\Throwable $e) {
                        // If conversion fails, fall back to simple link
                        return '<a href="/article/' . $this->e($bechEncoded) . '" class="nostr-link">' . $this->e($bechEncoded) . '</a>';
                    }
                }

                // Use generic event card for other addressable events
                return $this->twig->render('components/event_card.html.twig', [
                    'event'  => $event,
                    'author' => $authorMeta,
                    'naddr'  => $bechEncoded,
                ]);
            }

            default:
                return $this->e($bechEncoded);
        }
    }

    /**
     * Pre-fetch all Nostr entities referenced in the content and optional event tags.
     * Returns a NostrPrefetchedData object that both the CommonMark inline parsers
     * and the post-HTML processNostrLinks() phase can read from.
     *
     * @param string     $content Raw article content (Markdown/AsciiDoc)
     * @param array|null $tags    Optional Nostr event tags (each element: [tagName, value, ...])
     */
    private function prefetchNostrData(string $content, ?array $tags = null): NostrPrefetchedData
    {
        $pubkeyHexes = []; // hex => 1
        $eventIds    = []; // id  => 1
        $naddrCoords = []; // "kind:pubkey:d-tag" => 1

        // --- 1. Extract references from event tags (p, e, a) ---
        if ($tags) {
            foreach ($tags as $tag) {
                if (!is_array($tag) || empty($tag[0]) || !isset($tag[1])) {
                    continue;
                }
                switch ($tag[0]) {
                    case 'p':
                        if (NostrKeyUtil::isHexPubkey($tag[1])) {
                            $pubkeyHexes[$tag[1]] = 1;
                        }
                        break;
                    case 'e':
                        if (preg_match('/^[a-fA-F0-9]{64}$/', $tag[1])) {
                            $eventIds[$tag[1]] = 1;
                        }
                        break;
                    case 'a':
                        // Format: "kind:pubkey:d-tag"
                        $parts = explode(':', $tag[1], 3);
                        if (count($parts) === 3 && NostrKeyUtil::isHexPubkey($parts[1])) {
                            $naddrCoords[$tag[1]] = 1;
                            $pubkeyHexes[$parts[1]] = 1;
                        }
                        break;
                }
            }
        }

        // --- 2. Scan content for nostr: bech32 references ---
        preg_match_all(self::RE_ALL_NOSTR, $content, $mAll);
        if (!empty($mAll[0])) {
            $uniqueLinks = array_values(array_unique($mAll[0]));
            foreach ($uniqueLinks as $link) {
                $bech = substr($link, 6);
                try {
                    $decoded = new Bech32($bech);
                    switch ($decoded->type) {
                        case 'npub':
                            $hex = $this->nostrKeyUtil->npubToHex($bech);
                            $pubkeyHexes[$hex] = 1;
                            break;
                        case 'nprofile':
                            /** @var NProfile $obj */
                            $obj = $decoded->data;
                            $pubkeyHexes[$obj->pubkey] = 1;
                            break;
                        case 'note':
                            /** @var Note $obj */
                            $obj = $decoded->data;
                            $eventIds[$obj->data] = 1;
                            break;
                        case 'nevent':
                            /** @var NEvent $obj */
                            $obj = $decoded->data;
                            $eventIds[$obj->id] = 1;
                            break;
                        case 'naddr':
                            /** @var NAddr $obj */
                            $obj = $decoded->data;
                            $coordKey = $obj->kind . ':' . $obj->pubkey . ':' . $obj->identifier;
                            $naddrCoords[$coordKey] = 1;
                            $pubkeyHexes[$obj->pubkey] = 1;
                            break;
                    }
                } catch (\Throwable) {
                    // skip invalid
                }
            }
        }

        // --- 3. Batch fetch events by ID ---
        $eventsById = $this->fetchEventsById($eventIds, $pubkeyHexes);

        // --- 4. Batch fetch naddr events by coordinate ---
        $eventsByNaddr = $this->fetchEventsByNaddr(array_keys($naddrCoords), $pubkeyHexes);

        // Collect pubkeys from fetched naddr events as well
        foreach ($eventsByNaddr as $event) {
            if ($event && !empty($event->pubkey)) {
                $pubkeyHexes[$event->pubkey] = 1;
            }
        }

        // --- 5. Batch fetch all metadata ---
        $metadataByHex = $this->fetchMetadataByHex(array_keys($pubkeyHexes));

        return new NostrPrefetchedData($metadataByHex, $eventsById, $eventsByNaddr);
    }

    /**
     * Batch fetch events by naddr coordinate strings ("kind:pubkey:d-tag").
     *
     * @param string[] $coordinates Array of "kind:pubkey:d-tag" strings
     * @param array<string,int> $pubkeyHexes  Updated in-place with discovered pubkeys
     * @return array<string, object|null>  coordKey => event
     */
    private function fetchEventsByNaddr(array $coordinates, array &$pubkeyHexes): array
    {
        if (empty($coordinates)) {
            return [];
        }

        $eventsByNaddr = [];

        // Resolve from local database only — unresolved references become
        // deferred embed placeholders resolved at template render time.
        foreach ($coordinates as $coordKey) {
            $parts = explode(':', $coordKey, 3);
            if (count($parts) !== 3) {
                continue;
            }
            [$kind, $pubkey, $identifier] = $parts;
            try {
                $dbEvent = $this->eventRepository->findByNaddr((int) $kind, $pubkey, $identifier);
                if ($dbEvent) {
                    $obj = new \stdClass();
                    $obj->id = $dbEvent->getId();
                    $obj->kind = $dbEvent->getKind();
                    $obj->pubkey = $dbEvent->getPubkey();
                    $obj->content = $dbEvent->getContent();
                    $obj->created_at = $dbEvent->getCreatedAt();
                    $obj->tags = $dbEvent->getTags();
                    $obj->sig = $dbEvent->getSig();
                    $eventsByNaddr[$coordKey] = $obj;
                    if (!empty($obj->pubkey)) {
                        $pubkeyHexes[$obj->pubkey] = 1;
                    }
                }
            } catch (\Throwable) {
                // DB lookup failed; deferred embeds will handle at render time
            }
        }

        return $eventsByNaddr;
    }

    /**
     * Render a lightweight deferred embed placeholder for a nostr reference
     * that couldn't be resolved from local data.  The placeholder is resolved
     * at template render time by the `resolve_nostr_embeds` Twig filter.
     */
    private function renderDeferredEmbed(string $bech, string $type): string
    {
        $escapedBech = $this->e($bech);
        $escapedType = $this->e($type);
        return '<div class="nostr-deferred-embed" data-nostr-bech="' . $escapedBech . '" data-nostr-type="' . $escapedType . '"></div>';
    }

    private function labelFromKey(string $npub): string
    {
        $start = substr($npub, 0, 5);
        $end   = substr($npub, -5);
        return $start . '...' . $end;
    }

    private function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}
