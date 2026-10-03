<?php

declare(strict_types=1);

namespace DecentNewsroom\UnfoldBundle\Contract;

use DecentNewsroom\UnfoldBundle\Content\PostData;

interface ReaderBootstrapInterface
{
    public function render(PostData $post): string;
}
