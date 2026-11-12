<?php

declare(strict_types=1);

namespace App\Twig\Components\Molecules;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/** Presentation only; the URL must authorize the exact source on every page. */
#[AsTwigComponent('Molecules:EventTocDisclosure')]
final class EventTocDisclosure
{
    public string $eventId = '';
    public string $title = '';
    public string $url = '';
}
