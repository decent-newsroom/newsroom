<?php

declare(strict_types=1);

namespace App\Bookshelf;

use App\Dto\EventWorkspace\EventReferenceStatus;
use App\Dto\EventWorkspace\EventReferenceType;
use App\Entity\Event;
use App\Enum\KindsEnum;
use App\Service\EventWorkspace\DriveMountEventAccessInterface;

/** Adds authorized, local article metadata to a Bookshelf TOC page. */
final readonly class BookshelfTocArticles
{
    public function __construct(private DriveMountEventAccessInterface $access) {}

    /** @param array<string, mixed> $page @return array<string, mixed> */
    public function enrich(array $page): array
    {
        $entries = $page['entries'];
        if ($page['featuredEntry'] !== null) {
            $entries[] = $page['featuredEntry'];
        }
        $references = [];
        foreach ($entries as $entry) {
            $reference = $entry['reference'];
            if ($reference->type === EventReferenceType::Address
                && $reference->targetKind === KindsEnum::LONGFORM->value
                && $reference->status === EventReferenceStatus::Unresolved
                && !$entry['invalidVersionPin']) {
                $references[] = $reference;
            }
        }
        if ($references === []) {
            return $page;
        }

        $targets = $this->access->readableArticleTargets($references);
        foreach ($page['entries'] as &$entry) {
            $this->applyTarget($entry, $targets);
        }
        unset($entry);
        if ($page['featuredEntry'] !== null) {
            $this->applyTarget($page['featuredEntry'], $targets);
        }

        return $page;
    }

    /** @param array<string, mixed> $entry @param array<int, Event> $targets */
    private function applyTarget(array &$entry, array $targets): void
    {
        $target = $targets[$entry['reference']->position] ?? null;
        if (!$target instanceof Event) {
            return;
        }
        $entry['targetResolution'] = 'available';
        $entry['target'] = [
            'eventId' => strtolower($target->getId()),
            'title' => $this->textTag($target, 'title', 300),
            'summary' => $this->textTag($target, 'summary', 500),
            'sensitive' => false,
        ];
    }

    private function textTag(Event $event, string $name, int $maxLength): ?string
    {
        foreach ($event->getTags() as $tag) {
            if (is_array($tag) && ($tag[0] ?? null) === $name && is_string($tag[1] ?? null)) {
                return mb_substr($tag[1], 0, $maxLength);
            }
        }

        return null;
    }
}
