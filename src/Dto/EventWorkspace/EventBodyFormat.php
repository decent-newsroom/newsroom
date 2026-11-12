<?php

declare(strict_types=1);

namespace App\Dto\EventWorkspace;

/**
 * A conservative content capability, not a claim that a body is safe to render.
 */
enum EventBodyFormat: string
{
    case PlainText = 'plain_text';
    case Markdown = 'markdown';
    case AsciiDocOrText = 'asciidoc_or_text';
    case Opaque = 'opaque';
}
