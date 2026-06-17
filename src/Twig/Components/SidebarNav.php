<?php

declare(strict_types=1);

namespace App\Twig\Components;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * Reusable sidebar navigation component.
 *
 * Renders a nav structure with sections, each containing link items.
 * Used by layout.html.twig (global), reading-nook-layout.html.twig, and newsroom-layout.html.twig.
 */
#[AsTwigComponent]
class SidebarNav
{
    /**
     * @var array<int, array{label: string, items: array<int, array{label: string, route: string, icon?: string}>}>
     */
    public array $sections = [];

    /**
     * Optional: root-level link before sections (e.g., "Back to newsroom")
     */
    public ?array $backLink = null;

    /**
     * Optional: component to render after the nav sections
     */
    public ?string $footerComponent = null;
}

