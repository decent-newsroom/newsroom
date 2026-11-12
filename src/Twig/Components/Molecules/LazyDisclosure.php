<?php

declare(strict_types=1);

namespace App\Twig\Components\Molecules;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/** Presentation only: callers supply labels, exact identity and an authorized URL. */
#[AsTwigComponent('Molecules:LazyDisclosure')]
final class LazyDisclosure
{
    public string $frameId = '';
    public string $url = '';
    public string $summary = '';
    public string $loadLabel = '';
    public string $disclosureClass = '';
}
