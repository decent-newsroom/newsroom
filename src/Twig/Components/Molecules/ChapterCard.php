<?php

declare(strict_types=1);

namespace App\Twig\Components\Molecules;

use App\Entity\Event;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent('Molecules:ChapterCard')]
final class ChapterCard
{
    public Event $chapter;
    public string $mag;
    public string $slug = '';

    public function mount(): void
    {
        // Extract slug from d-tag
        foreach ($this->chapter->getTags() as $tag) {
            if ($tag[0] === 'd') {
                $this->slug = $tag[1];
                break;
            }
        }
    }
}

