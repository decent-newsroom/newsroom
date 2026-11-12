<?php

declare(strict_types=1);

namespace App\Service\EventWorkspace;

use App\Dto\EventWorkspace\EventReference;
use App\Dto\EventWorkspace\EventReferenceRole;
use App\Entity\Event;
use App\Enum\KindsEnum;

/**
 * Reference-only pages over an already-authorized publication index.
 * The caller enforces source access. No target is looked up or authorized here.
 */
final readonly class PublicationTocPage
{
    public const PAGE_SIZE = 50;

    public function __construct(
        private EventWorkspaceSourceAdapter $sources,
        private EventPresentationFactory $presentations,
    ) {}

    /**
     * @return array{
     *     eventId: string, page: int, start: int,
     *     entries: list<array{reference: EventReference, rawVersionPin: ?string, invalidVersionPin: bool}>,
     *     hasNext: bool, hasPrevious: bool
     * }
     */
    public function fromAuthorizedEvent(Event $event, int $page = 1): array
    {
        if ($event->getKind() !== KindsEnum::PUBLICATION_INDEX->value || $page < 1) {
            throw new \InvalidArgumentException('TOC pages require kind 30040 and a positive page number.');
        }

        $source = $this->sources->fromOwnedCollectionEvent($event);
        $result = [
            'eventId' => $source->eventId,
            'page' => $page,
            'start' => 1,
            'entries' => [],
            'hasNext' => false,
            'hasPrevious' => $page > 1,
        ];
        // Also keep the displayed ordinal within the integer range.
        if ($page - 1 > intdiv(PHP_INT_MAX - 1, self::PAGE_SIZE)) {
            return $result;
        }
        $offset = ($page - 1) * self::PAGE_SIZE;
        $result['start'] = $offset + 1;
        $tags = array_values($source->getRawTags() ?? []);
        $index = 0;
        foreach ($this->presentations->fromAuthorizedEvent($event)->references as $reference) {
            // NKBIP-01 ordering belongs to kind 30040 a tags, not e/E/p tags.
            if ($reference->role !== EventReferenceRole::Content) {
                continue;
            }
            if ($index++ < $offset) {
                continue;
            }
            if (count($result['entries']) === self::PAGE_SIZE) {
                $result['hasNext'] = true;
                break;
            }

            $tag = $tags[$reference->position];
            $hasPin = array_key_exists(3, $tag) && $tag[3] !== '';
            $result['entries'][] = [
                'reference' => $reference,
                // Retain malformed pins too; never reinterpret one as "latest".
                'rawVersionPin' => is_string($tag[3] ?? null) ? $tag[3] : null,
                'invalidVersionPin' => $hasPin && $reference->versionEventId === null,
            ];
        }

        return $result;
    }
}
