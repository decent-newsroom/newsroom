<?php

declare(strict_types=1);

namespace App\Unfold;

use DecentNewsroom\UnfoldBundle\Content\PostData;
use DecentNewsroom\UnfoldBundle\Contract\ReaderBootstrapInterface;
use Twig\Environment;

final readonly class ReaderBootstrapAdapter implements ReaderBootstrapInterface
{
    public function __construct(private Environment $twig) {}

    public function render(PostData $post): string
    {
        return $this->twig->render('bundles/UnfoldBundle/reader/_bootstrap.html.twig');
    }
}
