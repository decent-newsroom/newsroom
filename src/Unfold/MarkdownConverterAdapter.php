<?php

declare(strict_types=1);

namespace App\Unfold;

use App\Util\CommonMark\MarkdownConverterInterface as HostMarkdownConverter;
use DecentNewsroom\UnfoldBundle\Contract\MarkdownConverterInterface;

final readonly class MarkdownConverterAdapter implements MarkdownConverterInterface
{
    public function __construct(private HostMarkdownConverter $converter)
    {
    }

    public function convertToHTML(
        string $content,
        ?string $format = null,
        ?int $kind = null,
        ?array $tags = null,
    ): string {
        return $this->converter->convertToHTML($content, $format, $kind, $tags);
    }
}
