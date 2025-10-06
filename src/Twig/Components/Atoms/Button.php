<?php

namespace App\Twig\Components\Atoms;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent]
final class Button
{
    public string $variant = 'default';
    public string $tag = 'button';

    public function getVariantClasses(): string
    {
        return match ($this->variant) {
            'success' => 'btn-success',
            'danger' => 'btn-danger',
            'accent' => 'btn-accent btn-sm',
            default => 'btn-primary',
        };
    }
}
