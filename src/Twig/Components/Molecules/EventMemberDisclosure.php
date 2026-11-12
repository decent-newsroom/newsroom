<?php

declare(strict_types=1);

namespace App\Twig\Components\Molecules;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * Presentation-only disclosure. The supplied URL must authorize every page.
 */
#[AsTwigComponent('Molecules:EventMemberDisclosure')]
final class EventMemberDisclosure
{
    public string $eventId = '';
    public string $title = '';
    public string $url = '';
}
