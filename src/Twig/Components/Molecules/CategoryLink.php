<?php

namespace App\Twig\Components\Molecules;

use Symfony\Contracts\Cache\CacheInterface;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent]
final class CategoryLink
{
    public string $title;
    public string $slug;
    public ?string $mag = null; // magazine slug passed from parent (optional)

    public function __construct(private CacheInterface $redisCache)
    {
    }

    public function mount($coordinate): void
    {
        if (key_exists(1, $coordinate)) {
            $parts = explode(':', $coordinate[1], 3);
            // Expect format kind:pubkey:slug
            $this->slug = $parts[2] ?? '';
            $cat = $this->redisCache->get('magazine-' . $this->slug, function (){
                return null;
            });

            if ($cat === null) {
                $this->title = $this->slug ?: 'Category';
                return;
            }

            $tags = $cat->getTags();

            $title = array_filter($tags, function($tag) {
                return ($tag[0] === 'title');
            });

            $this->title = $title[array_key_first($title)][1] ?? ($this->slug ?: 'Category');
        } else {
            $this->title = 'Category';
            $this->slug = '';
        }

    }

}
