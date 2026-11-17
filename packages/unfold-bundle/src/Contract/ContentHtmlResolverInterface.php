<?php

declare(strict_types=1);

namespace DecentNewsroom\UnfoldBundle\Contract;

/**
 * Resolves viewer-dependent content after the shared HTML cache is read.
 * The result is sanitized again before it is handed to a theme.
 */
interface ContentHtmlResolverInterface
{
    public function resolveContentHtml(string $html): string;
}
