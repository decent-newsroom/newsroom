<?php

declare(strict_types=1);

namespace App\Twig\Components\Molecules;

use App\Dto\EventWorkspace\EventPresentation;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * Presents an event snapshot that the caller has already authorized.
 */
#[AsTwigComponent('Molecules:EventPreview')]
final class EventPreview
{
    public EventPresentation $presentation;
    /** Callers hiding this heading must render their own sensitivity-aware label. */
    public bool $showHeading = true;
    public bool $showByline = true;
    public bool $showAuthorDetails = true;
}
