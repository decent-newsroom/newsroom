<?php

declare(strict_types=1);

namespace App\Twig\Components\Organisms;

use App\Entity\Event;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent]
final class MagazineHero
{
    use DefaultActionTrait;

    /**
     * The magazine object/array (entity or hydrated structure) provided by the controller.
     * @var mixed $magazine
     */
    public ?Event $magazine = null;

    /**
     * Slug/identifier for the magazine (route parameter), optional fallback if magazine slug not present.
     */
    public ?string $mag = null;

    /**
     * Compute category tags (those whose first element/key is the letter 'a').
     * Supports multiple magazine shapes (object with getTags(), public property, or array key).
     *
     * @return array<int, mixed>
     */
    public function getCategoryTags(): array
    {
        $tags = [];
        $magazine = $this->magazine;

        if ($magazine) {
            $tags = $magazine->getTags();
        }

        if (!is_iterable($tags)) {
            return [];
        }

        // Filter: tag[0] === 'a'
        $filtered = [];
        foreach ($tags as $tag) {
            if (is_array($tag) && isset($tag[0]) && $tag[0] === 'a') {
                $filtered[] = $tag;
            }
        }

        return $filtered;
    }
}
