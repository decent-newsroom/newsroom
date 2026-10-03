<?php

declare(strict_types=1);

namespace DecentNewsroom\UnfoldBundle\Content;

final class AmbiguousContentException extends \RuntimeException
{
    /** @param list<PostData> $posts */
    public function __construct(public readonly array $posts)
    {
        parent::__construct('Multiple authors use this article identifier.');
    }
}
