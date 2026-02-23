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
     * Preview mode: when true, uses the plain string props below instead of the Event entity.
     * Useful in the wizard where no published Event exists yet.
     */
    public bool $preview = false;

    /** Preview-mode props — used when $preview is true */
    public ?string $previewTitle = null;
    public ?string $previewSummary = null;
    public ?string $previewImageUrl = null;

    /** Whether the current user owns this magazine */
    public bool $isOwner = false;

    /**
     * Get the resolved title for display.
     */
    public function getDisplayTitle(): string
    {
        if ($this->preview) {
            return $this->previewTitle ?: 'Your Magazine Title';
        }

        if ($this->magazine) {
            return $this->magazine->getTitle() ?? $this->magazine->getSlug() ?? $this->mag ?? '';
        }

        return $this->mag ?? '';
    }

    /**
     * Get the resolved summary for display.
     */
    public function getDisplaySummary(): ?string
    {
        if ($this->preview) {
            return $this->previewSummary ?: null;
        }

        if ($this->magazine) {
            return $this->magazine->getSummary();
        }

        return null;
    }

    /**
     * Get the resolved cover image URL for display.
     */
    public function getDisplayImage(): ?string
    {
        if ($this->preview) {
            return $this->previewImageUrl ?: null;
        }

        if ($this->magazine) {
            return $this->magazine->getImage();
        }

        return null;
    }

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
