<?php

declare(strict_types=1);

namespace App\Service\EventWorkspace;

use App\Dto\EventWorkspace\EventReference;
use App\Dto\EventWorkspace\EventReferenceRole;
use App\Dto\EventWorkspace\EventReferenceStatus;
use App\Dto\EventWorkspace\EventPresentation;
use App\Entity\Event;
use App\Enum\KindsEnum;
use App\Repository\DeletedEventRepository;
use App\Repository\EventRepository;
use App\Repository\HiddenCoordinateRepository;
use App\Service\Reader\ContentAuthorAccessPolicy;
use App\Service\EventWorkspace\Metrics\WorkspaceMetrics;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Bounded TOC pages for an authorized root index or locally available nested
 * indexes. Callers authorize each source event; nested targets are batch-read
 * and filtered through hidden-coordinate, reader-mute, author-suppression, and
 * tombstone policy before metadata is exposed.
 */
final readonly class PublicationTocPage
{
    public const PAGE_SIZE = 50;
    public const MAX_EXPANSION_DEPTH = 6;

    public function __construct(
        private EventWorkspaceSourceAdapter $sources,
        private EventPresentationFactory $presentations,
        private EventRepository $events,
        private DeletedEventRepository $deletions,
        private ContentAuthorAccessPolicy $authorAccess,
        private HiddenCoordinateRepository $hiddenCoordinates,
        private PublicationTocTraversalSigner $traversalSigner,
        private ?WorkspaceMetrics $metrics = null,
    ) {}

    /**
     * @return array{
     *     eventId: string, page: int, start: int, frameId: string,
     *     path: list<string>, occurrenceId: ?string, routeName: string,
     *     coordinate: string, pageSignatures: array{previous: ?string, next: ?string},
     *     entries: list<array<string, mixed>>,
     *     hasNext: bool, hasPrevious: bool
     * }
     * @param list<string> $ancestorEventIds
     * @param list<string> $readerMutedPubkeys
     */
    public function fromAuthorizedEvent(
        Event $event,
        int $page = 1,
        array $ancestorEventIds = [],
        ?string $occurrenceId = null,
        string $routeName = 'reading_nook_publication_toc',
        array $readerMutedPubkeys = [],
        ?int $resolutionPosition = null,
    ): array
    {
        if ($event->getKind() !== KindsEnum::PUBLICATION_INDEX->value || $page < 1) {
            throw new \InvalidArgumentException('TOC pages require kind 30040 and a positive page number.');
        }
        if (EventWorkspaceScopePolicy::isScopedInventoryList($event->getKind(), $event->getTags())) {
            throw new NotFoundHttpException();
        }
        if (count($ancestorEventIds) >= self::MAX_EXPANSION_DEPTH
            || ($occurrenceId !== null && preg_match('/^[0-9a-f]{64}$/', $occurrenceId) !== 1)) {
            throw new \InvalidArgumentException('TOC nesting is outside its supported depth.');
        }

        $source = $this->sources->fromOwnedCollectionEvent($event);
        $eventPath = [...$ancestorEventIds, strtolower($event->getId())];
        $coordinate = $this->coordinateFromEvent($event);
        $result = [
            'eventId' => $source->eventId,
            'page' => $page,
            'start' => 1,
            'frameId' => $occurrenceId === null
                ? 'nook-toc-' . $source->eventId
                : 'nook-toc-nested-' . $occurrenceId,
            'ancestorPath' => $ancestorEventIds,
            'path' => $eventPath,
            'occurrenceId' => $occurrenceId,
            'routeName' => $routeName,
            'coordinate' => $coordinate,
            'discoverySignature' => $occurrenceId !== null
                ? $this->traversalSigner->sign($source->eventId, $page, $ancestorEventIds, $occurrenceId, $coordinate)
                : null,
            'pageSignatures' => [
                'previous' => $occurrenceId !== null && $page > 1
                    ? $this->traversalSigner->sign($source->eventId, $page - 1, $ancestorEventIds, $occurrenceId, $coordinate)
                    : null,
                'next' => null,
            ],
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
                'targetResolution' => null,
                'target' => null,
                'chapterPreviewOccurrenceId' => null,
                'chapterPreviewSignature' => null,
                'cycle' => false,
                'depthLimited' => false,
                'nestedPath' => null,
                'nestedOccurrenceId' => null,
            ];
        }

        $this->resolveNestedIndexes($result['entries'], $eventPath, $occurrenceId, $readerMutedPubkeys, $resolutionPosition);
        $this->resolvePinnedChapters(
            $result['entries'],
            $readerMutedPubkeys,
            $resolutionPosition,
            $eventPath,
            $occurrenceId,
        );
        if ($result['hasNext'] && $occurrenceId !== null && $page < PHP_INT_MAX) {
            $result['pageSignatures']['next'] = $this->traversalSigner->sign(
                $source->eventId,
                $page + 1,
                $ancestorEventIds,
                $occurrenceId,
                $coordinate,
            );
        }

        foreach ($result['entries'] as $entry) {
            $status = $entry['targetResolution']
                ?? ($entry['invalidVersionPin'] ? 'invalid' : $entry['reference']->status->value);
            $this->metrics?->resolution($status);
        }

        return $result;
    }

    /**
     * Resolve only visible nested-index occurrences; ordinary `e` references
     * and non-index `a` references remain reference-only.
     *
     * @param list<array<string, mixed>> $entries
     * @param list<string> $eventPath
     * @param list<string> $readerMutedPubkeys
     */
    private function resolveNestedIndexes(
        array &$entries,
        array $eventPath,
        ?string $occurrenceId,
        array $readerMutedPubkeys,
        ?int $resolutionPosition,
    ): void
    {
        $coordinates = [];
        $eventIds = [];
        $requests = [];
        foreach ($entries as $index => $entry) {
            /** @var EventReference $reference */
            $reference = $entry['reference'];
            if ($resolutionPosition !== null && $reference->position !== $resolutionPosition) {
                continue;
            }
            if ($reference->role !== EventReferenceRole::Content
                || $reference->type->value !== 'address'
                || $reference->targetKind !== KindsEnum::PUBLICATION_INDEX->value
                || $reference->target === null
                || $reference->status !== EventReferenceStatus::Unresolved
                || $entry['invalidVersionPin']) {
                continue;
            }

            if ($reference->versionEventId !== null) {
                $eventIds[] = $reference->versionEventId;
                $requests[$index] = ['eventId' => $reference->versionEventId];
            } else {
                $coordinates[] = $reference->target;
                $requests[$index] = ['coordinate' => $reference->target];
            }
        }
        if ($requests === []) {
            return;
        }

        $targets = $this->events->findPublicationIndexTargets($coordinates, $eventIds);
        $candidateTargets = [];
        foreach ($requests as $index => $identity) {
            /** @var EventReference $reference */
            $reference = $entries[$index]['reference'];
            $target = isset($identity['eventId'])
                ? ($targets['eventIds'][$identity['eventId']] ?? null)
                : ($targets['coordinates'][$identity['coordinate']] ?? null);
            if (!$target instanceof Event) {
                $entries[$index]['targetResolution'] = 'unavailable';
                continue;
            }

            if (!$this->matchesAddress($target, $reference)
                || EventWorkspaceScopePolicy::isScopedTarget($target->getKind(), $target->getTags())) {
                // In particular, never fall back from an invalid exact pin to
                // whichever revision is current at the coordinate. The generic
                // status also avoids disclosing whether the pin exists.
                $entries[$index]['targetResolution'] = 'unavailable';
                continue;
            }
            $entries[$index]['candidateEventId'] = strtolower($target->getId());
            $candidateTargets[strtolower($target->getId())] = $target;
        }

        if ($candidateTargets === []) {
            return;
        }

        // Hidden-coordinate filtering runs in one bounded query before any
        // target metadata or author/deletion policy result is exposed.
        $targetCoordinates = [];
        foreach ($candidateTargets as $target) {
            $targetCoordinates[strtolower($target->getId())] = $this->coordinateFromEvent($target);
        }
        $hidden = array_fill_keys(
            $this->hiddenCoordinates->findHiddenCoordinates(array_values($targetCoordinates)),
            true,
        );
        $muted = array_fill_keys(array_map('strtolower', $readerMutedPubkeys), true);
        $validTargets = [];
        foreach ($requests as $index => $identity) {
            $targetId = $entries[$index]['candidateEventId'] ?? null;
            if (!is_string($targetId)) {
                continue;
            }
            $target = $candidateTargets[$targetId];
            $targetCoordinate = $targetCoordinates[$targetId];
            if (isset($hidden[$targetCoordinate]) || isset($muted[strtolower($target->getPubkey())])) {
                $entries[$index]['targetResolution'] = 'unavailable';
                unset($entries[$index]['candidateEventId']);
                continue;
            }
            $validTargets[$targetId] = $target;
        }

        if ($validTargets === []) {
            return;
        }

        $suppressedAuthors = array_fill_keys(
            $this->authorAccess->suppressedPubkeys(array_values(array_unique(array_map(
                static fn (Event $target): string => strtolower($target->getPubkey()),
                array_values($validTargets),
            )))),
            true,
        );
        $deletedEvents = array_fill_keys(
            $this->deletions->findSuppressedEventIds(array_values($validTargets)),
            true,
        );

        foreach ($requests as $index => $identity) {
            $targetId = $entries[$index]['candidateEventId'] ?? null;
            if (!is_string($targetId)) {
                continue;
            }
            $target = $validTargets[$targetId];
            if (isset($suppressedAuthors[strtolower($target->getPubkey())]) || isset($deletedEvents[$targetId])) {
                // Keep the occurrence and coordinate, but do not disclose that
                // a suppressed/deleted row exists or any of its metadata.
                $entries[$index]['targetResolution'] = 'unavailable';
                unset($entries[$index]['candidateEventId']);
                continue;
            }

            $sensitive = EventPresentation::hasSensitiveTagEvidence($target->getTags());
            $entries[$index]['targetResolution'] = 'available';
            $entries[$index]['target'] = [
                'eventId' => strtolower($target->getId()),
                'pubkey' => strtolower($target->getPubkey()),
                'coordinate' => $targetCoordinates[$targetId],
                'title' => $sensitive ? null : $this->textTag($target, 'title', 300),
                'summary' => $sensitive ? null : $this->textTag($target, 'summary', 500),
                'sensitive' => $sensitive,
            ];
            $entries[$index]['cycle'] = in_array(strtolower($target->getId()), array_map('strtolower', $eventPath), true);
            $entries[$index]['depthLimited'] = !$entries[$index]['cycle']
                && count($eventPath) >= self::MAX_EXPANSION_DEPTH;
            if (!$entries[$index]['cycle'] && !$entries[$index]['depthLimited']) {
                $entries[$index]['nestedPath'] = $eventPath;
                $entries[$index]['nestedOccurrenceId'] = hash(
                    'sha256',
                    ($occurrenceId ?? 'root:' . strtolower($entries[$index]['reference']->sourceEventId))
                    . ':' . $entries[$index]['reference']->position
                    . ':' . strtolower($target->getId()),
                );
                $entries[$index]['nestedSignature'] = $this->traversalSigner->sign(
                    strtolower($target->getId()),
                    1,
                    $eventPath,
                    $entries[$index]['nestedOccurrenceId'],
                    $targetCoordinates[$targetId],
                );
            }
            unset($entries[$index]['candidateEventId']);
        }
    }

    /**
     * Exact chapter pins are previewed only when that event is already local.
     * Cold pins remain visible as references but are never fetched or upgraded.
     *
     * @param list<array<string, mixed>> $entries
     * @param list<string> $readerMutedPubkeys
     * @param list<string> $eventPath
     */
    private function resolvePinnedChapters(
        array &$entries,
        array $readerMutedPubkeys,
        ?int $resolutionPosition,
        array $eventPath,
        ?string $occurrenceId,
    ): void {
        $requests = [];
        $eventIds = [];
        foreach ($entries as $index => $entry) {
            /** @var EventReference $reference */
            $reference = $entry['reference'];
            if (($resolutionPosition !== null && $reference->position !== $resolutionPosition)
                || $reference->role !== EventReferenceRole::Content
                || $reference->type->value !== 'address'
                || $reference->targetKind !== KindsEnum::PUBLICATION_CONTENT->value
                || $reference->target === null
                || $reference->versionEventId === null
                || $reference->status !== EventReferenceStatus::Unresolved
                || $entry['invalidVersionPin']) {
                continue;
            }

            $requests[$index] = $reference;
            $eventIds[] = $reference->versionEventId;
        }
        if ($requests === []) {
            return;
        }

        $targets = $this->events->findWorkspacePreviewTargets([], $eventIds)['eventIds'];
        $candidateTargets = [];
        foreach ($requests as $index => $reference) {
            $target = $targets[$reference->versionEventId] ?? null;
            if (!$target instanceof Event) {
                continue;
            }
            if (!$this->matchesAddress($target, $reference)
                || EventWorkspaceScopePolicy::isScopedTarget($target->getKind(), $target->getTags())) {
                $entries[$index]['targetResolution'] = 'unavailable';
                continue;
            }
            $targetId = strtolower($target->getId());
            $entries[$index]['candidateEventId'] = $targetId;
            $candidateTargets[$targetId] = $target;
        }
        if ($candidateTargets === []) {
            return;
        }

        $targetCoordinates = [];
        foreach ($candidateTargets as $targetId => $target) {
            $targetCoordinates[$targetId] = $this->coordinateFromEvent($target);
        }
        $hidden = array_fill_keys(
            $this->hiddenCoordinates->findHiddenCoordinates(array_values($targetCoordinates)),
            true,
        );
        $muted = array_fill_keys(array_map('strtolower', $readerMutedPubkeys), true);
        $allowedTargets = [];
        foreach ($candidateTargets as $targetId => $target) {
            if (!isset($hidden[$targetCoordinates[$targetId]])
                && !isset($muted[strtolower($target->getPubkey())])) {
                $allowedTargets[$targetId] = $target;
            }
        }
        if ($allowedTargets === []) {
            foreach ($requests as $index => $reference) {
                if (isset($entries[$index]['candidateEventId'])) {
                    $entries[$index]['targetResolution'] = 'unavailable';
                    unset($entries[$index]['candidateEventId']);
                }
            }

            return;
        }

        $suppressedAuthors = array_fill_keys(
            $this->authorAccess->suppressedPubkeys(array_values(array_unique(array_map(
                static fn (Event $target): string => strtolower($target->getPubkey()),
                array_values($allowedTargets),
            )))),
            true,
        );
        $deletedEvents = array_fill_keys(
            $this->deletions->findSuppressedEventIds(array_values($allowedTargets)),
            true,
        );
        foreach ($requests as $index => $reference) {
            $targetId = $entries[$index]['candidateEventId'] ?? null;
            if (!is_string($targetId)) {
                continue;
            }
            $target = $allowedTargets[$targetId] ?? null;
            if (!$target instanceof Event
                || isset($suppressedAuthors[strtolower($target->getPubkey())])
                || isset($deletedEvents[$targetId])) {
                $entries[$index]['targetResolution'] = 'unavailable';
                unset($entries[$index]['candidateEventId']);
                continue;
            }

            $presentation = $this->presentations->fromAuthorizedTargetMetadata($target);
            $sensitive = EventPresentation::hasSensitiveTagEvidence($target->getTags());
            $entries[$index]['targetResolution'] = 'available';
            $entries[$index]['target'] = [
                'eventId' => $presentation->eventId,
                'pubkey' => $presentation->authorPubkey,
                'coordinate' => $presentation->coordinate,
                'title' => $sensitive ? null : $this->textTag($target, 'title', 300),
                'summary' => $sensitive ? null : $this->textTag($target, 'summary', 500),
                'sensitive' => $sensitive,
            ];
            $entries[$index]['chapterPreviewOccurrenceId'] = hash(
                'sha256',
                ($occurrenceId ?? 'root:' . strtolower($reference->sourceEventId))
                    . ':chapter:' . $reference->position . ':' . strtolower($presentation->eventId),
            );
            $entries[$index]['chapterPreviewSignature'] = $presentation->coordinate !== null
                ? $this->traversalSigner->sign(
                    strtolower($presentation->eventId),
                    1,
                    $eventPath,
                    $entries[$index]['chapterPreviewOccurrenceId'],
                    $presentation->coordinate,
                )
                : null;
            unset($entries[$index]['candidateEventId']);
        }
    }

    private function matchesAddress(Event $event, EventReference $reference): bool
    {
        return $event->getKind() === $reference->targetKind
            && strtolower($event->getPubkey()) === strtolower((string) $reference->targetPubkey)
            && $this->dTagFromEvent($event) === $reference->targetIdentifier;
    }

    private function dTagFromEvent(Event $event): string
    {
        foreach ($event->getTags() as $tag) {
            if (($tag[0] ?? null) === 'd' && is_string($tag[1] ?? null)) {
                return $tag[1];
            }
        }

        return $event->getDTag() ?? '';
    }

    private function coordinateFromEvent(Event $event): string
    {
        return $event->getKind() . ':'
            . strtolower($event->getPubkey()) . ':'
            . $this->dTagFromEvent($event);
    }

    private function textTag(Event $event, string $name, int $maxLength): ?string
    {
        foreach ($event->getTags() as $tag) {
            if (($tag[0] ?? null) === $name && is_string($tag[1] ?? null)) {
                return mb_substr($tag[1], 0, $maxLength);
            }
        }

        return null;
    }
}
