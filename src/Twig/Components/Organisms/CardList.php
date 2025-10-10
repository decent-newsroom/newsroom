<?php

namespace App\Twig\Components\Organisms;

use swentel\nostr\Event\Event;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent]
final class CardList
{
    public array $list;
    public ?string $mag = null; // magazine slug passed from parent (optional)
    public ?Event $category = null; // category index passed from parent (optional)
    public array $authorsMetadata = [];
}
