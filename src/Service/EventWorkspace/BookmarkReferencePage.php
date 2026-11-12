<?php

declare(strict_types=1);

namespace App\Service\EventWorkspace;

use App\Dto\EventWorkspace\EventReference;
use App\Dto\EventWorkspace\EventReferenceRole;
use App\Dto\EventWorkspace\EventReferenceStatus;
use App\Entity\Event;
use App\Enum\KindsEnum;
use App\Service\Nostr\NostrNip19Service;

/**
 * Paginated, read-only view of the ordered references in a bookmark list.
 *
 * The caller authorizes the source event. Targets are resolved only for the
 * visible page, without fetching or exposing the source event body.
 */
final readonly class BookmarkReferencePage
{
    public const PAGE_SIZE = 50;

    public function __construct(
        private EventWorkspaceSourceAdapter $sources,
        private EventPresentationFactory $presentations,
        private NostrNip19Service $nip19,
        private TargetPreviewResolver $targets,
    ) {}

    /**
     * @return array{
     *     eventId: string,
     *     page: int,
     *     entries: list<array<string, mixed>>,
     *     hasNext: bool,
     *     hasPrevious: bool
     * }
     */
    public function fromAuthorizedEvent(Event $event, int $page = 1): array
    {
        if (!in_array($event->getKind(), [KindsEnum::BOOKMARKS->value, KindsEnum::BOOKMARK_SETS->value], true)) {
            throw new \InvalidArgumentException('Bookmark pages require kind 10003 or 30003.');
        }
        if ($page < 1) {
            throw new \InvalidArgumentException('Bookmark page numbers must be positive.');
        }

        $source = $this->sources->fromOwnedCollectionEvent($event);
        $referencesByPosition = [];
        foreach ($this->presentations->fromAuthorizedEvent($event)->references as $reference) {
            if ($reference->role === EventReferenceRole::Bookmark) {
                $referencesByPosition[$reference->position] = $reference;
            }
        }

        $result = [
            'eventId' => $source->eventId,
            'page' => $page,
            'entries' => [],
            'hasNext' => false,
            'hasPrevious' => $page > 1,
        ];
        if ($page - 1 > intdiv(PHP_INT_MAX, self::PAGE_SIZE)) {
            return $result;
        }

        $offset = ($page - 1) * self::PAGE_SIZE;
        $entryIndex = 0;
        $position = 0;
        foreach ($source->getRawTags() ?? [] as $tag) {
            $sourcePosition = $position++;
            $tagName = is_array($tag) ? ($tag[0] ?? null) : null;
            if (!is_string($tagName) || !in_array($tagName, ['a', 'e', 'p', 't'], true)) {
                continue;
            }
            if ($entryIndex++ < $offset) {
                continue;
            }
            if (count($result['entries']) === self::PAGE_SIZE) {
                $result['hasNext'] = true;
                break;
            }

            $rawValue = is_string($tag[1] ?? null) ? $tag[1] : null;
            $result['entries'][] = $this->presentEntry(
                $sourcePosition,
                $tagName,
                $rawValue,
                $referencesByPosition[$sourcePosition] ?? null,
            );
        }

        $visibleReferences = [];
        foreach ($result['entries'] as $entry) {
            if (isset($referencesByPosition[$entry['position']])) {
                $visibleReferences[] = $referencesByPosition[$entry['position']];
            }
        }
        $resolutions = $this->targets->resolvePage($visibleReferences);
        foreach ($result['entries'] as &$entry) {
            $entry['targetResolution'] = $resolutions[$entry['position']] ?? null;
        }
        unset($entry);

        return $result;
    }

    /** @return array{position: int, type: string, value: ?string, rawValue: ?string, invalid: bool} */
    private function presentEntry(int $position, string $tagName, ?string $rawValue, ?EventReference $reference): array
    {
        if ($tagName === 't') {
            $invalid = $rawValue === null || trim($rawValue) === '';

            return [
                'position' => $position,
                'type' => $invalid ? 'invalid' : 'topic',
                'value' => $invalid ? $rawValue : '#' . trim($rawValue),
                'rawValue' => $rawValue,
                'invalid' => $invalid,
            ];
        }

        if ($reference === null || $reference->status !== EventReferenceStatus::Unresolved || $reference->target === null) {
            return [
                'position' => $position,
                'type' => 'invalid',
                'value' => $rawValue,
                'rawValue' => $rawValue,
                'invalid' => true,
            ];
        }

        $value = match ($tagName) {
            'e' => $this->nip19->encodeNote($reference->target),
            'p' => $this->nip19->encodeNpub($reference->target),
            'a' => $reference->targetKind !== null
                && $reference->targetKind >= 30000
                && $reference->targetKind <= 39999
                && $reference->targetPubkey !== null
                && $reference->targetIdentifier !== null
                    ? $this->nip19->encodeAddr($reference->targetPubkey, $reference->targetIdentifier, $reference->targetKind)
                    : $rawValue,
            default => null,
        };

        return [
            'position' => $position,
            'type' => match ($tagName) {
                'a' => 'address',
                'e' => 'event',
                'p' => 'profile',
                default => 'invalid',
            },
            'value' => $value,
            'rawValue' => $rawValue,
            'invalid' => $value === null,
        ];
    }
}
