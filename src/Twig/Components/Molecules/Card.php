<?php

namespace App\Twig\Components\Molecules;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent]
final class Card
{
    /** @var list<list<string>>|null */
    public ?array $category = null; // category index tags passed from parent (optional)
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

    /** @param list<list<string>>|null $category */
    public function mount(?array $category = null): void
    {
        if ($category !== null) {
            $dTag = array_filter($category, function($tag) {
                return ($tag[0] === 'd');
            });
            $selectedTag = array_pop($dTag);
            $this->cat = $selectedTag[1] ?? null;
        }
    }

}
