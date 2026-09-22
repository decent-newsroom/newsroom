<?php

namespace App\Twig\Components\Molecules;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent]
final class CardPlaceholder
{
    public mixed $item;
    public string $heading = 'card.placeholder.heading';
    public string $description = 'card.placeholder.description';
    public string $fetchLabel = 'card.placeholder.fetchArticle';
    public string $variant = '';
}
