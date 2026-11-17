<?php

declare(strict_types=1);

namespace App\Unfold;

use App\Util\CommonMark\MarkdownConverterInterface as HostMarkdownConverter;
use App\Twig\NostrEmbedRuntime;
use DecentNewsroom\UnfoldBundle\Contract\ContentHtmlResolverInterface;
use DecentNewsroom\UnfoldBundle\Contract\MarkdownConverterInterface;

final readonly class MarkdownConverterAdapter implements MarkdownConverterInterface, ContentHtmlResolverInterface
{
    public function __construct(
        private HostMarkdownConverter $converter,
        private NostrEmbedRuntime $embeds,
    )
    {
    }

    /** @param array<array-key, mixed>|null $tags */
    public function convertToHTML(
        string $content,
        ?string $format = null,
        ?int $kind = null,
        ?array $tags = null,
    ): string {
        return $this->converter->convertToHTML($content, $format, $kind, $tags);
    }

    public function resolveContentHtml(string $html): string
    {
        return $this->embeds->resolveEmbeds($html, checkLegacyCards: false, highlightOnly: true);
    }
}
