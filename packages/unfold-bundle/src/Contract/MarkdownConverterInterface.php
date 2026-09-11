<?php

declare(strict_types=1);

namespace DecentNewsroom\UnfoldBundle\Contract;

interface MarkdownConverterInterface
{
    public function convertToHTML(
        string $content,
        ?string $format = null,
        ?int $kind = null,
        ?array $tags = null,
    ): string;
}
