<?php

declare(strict_types=1);

namespace App\Twig\Components\Molecules;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * Presentation-only lazy disclosure for an authorized bookmark-list route.
 */
#[AsTwigComponent('Molecules:EventBookmarkDisclosure')]
final class EventBookmarkDisclosure
{
    public string $eventId = '';
    public string $title = '';
    public string $url = '';
}
