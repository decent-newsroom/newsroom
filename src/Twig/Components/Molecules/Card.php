<?php

namespace App\Twig\Components\Molecules;

use swentel\nostr\Event\Event;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent]
final class Card
{
    public ?Event $category = null; // category index passed from parent (optional)
    public ?string $cat = "abc"; // computed category slug from $category (optional)
    public ?string $mag = null; // magazine slug passed from parent (optional)
    public object $article;

    public function mount($category)
    {
        if ($category) {
            $tags = $category->getTags();
            $dTag = array_filter($tags, function($tag) {
                return ($tag[0] === 'd');
            });
            $this->cat = array_pop($dTag)[1];
        }
    }

}
