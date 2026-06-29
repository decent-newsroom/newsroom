<?php

namespace App\Twig\Components\Molecules;

use swentel\nostr\Event\Event;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent]
final class Card
{
    public ?Event $category = null; // category index passed from parent (optional)
    public ?string $cat = null; // computed category slug from $category (optional)
    public ?string $mag = null; // magazine slug passed from parent (optional)
    /** @var object|array<string, mixed> */
    public object|array $article;
    public bool $is_author_profile = false;
    /** @var array<string, mixed> */
    public array $authors_metadata = [];
    public int $comment_count = 0;
    /** @var string[] */
    public array $source_labels = [];
    public ?string $category_label = null;

    public function mount(?Event $category = null): void
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
