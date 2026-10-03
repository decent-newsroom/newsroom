<?php

declare(strict_types=1);

namespace DecentNewsroom\UnfoldBundle\Contract;

use DecentNewsroom\UnfoldBundle\Content\PostData;

final readonly class InteractionTarget
{
    public function __construct(
        public string $publicationCoordinate,
        public PostData $post,
        public ?NostrEvent $original = null,
        public ?string $relayHint = null,
    ) {}
}
