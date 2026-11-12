<?php

declare(strict_types=1);

namespace App\Twig\Components\Molecules;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent('Molecules:BookshelfDirectoryDisclosure')]
final class BookshelfDirectoryDisclosure
{
    public string $url = '';
}
