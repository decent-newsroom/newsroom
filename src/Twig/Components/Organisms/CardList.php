<?php

namespace App\Twig\Components\Organisms;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent]
final class CardList
{
    public array $list;
    public ?string $mag = null; // magazine slug passed from parent (optional)
    /** @var list<list<string>>|null */
    public ?array $category = null; // category index tags passed from parent (optional)
    public array $authorsMetadata = [];
}
