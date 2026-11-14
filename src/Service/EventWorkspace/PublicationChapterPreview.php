<?php

declare(strict_types=1);

namespace App\Service\EventWorkspace;

use App\Dto\EventWorkspace\EventPresentation;
use App\Dto\EventWorkspace\EventReference;
use App\Dto\EventWorkspace\EventReferenceRole;
use App\Dto\EventWorkspace\EventReferenceStatus;
use App\Entity\Event;
use App\Enum\KindsEnum;
use App\Repository\EventRepository;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Loads one locally stored kind-30041 occurrence from an already-authorized
 * publication index. This is a leaf preview; it never expands chapter links.
 */
final readonly class PublicationChapterPreview
{
    public function __construct(
        private EventRepository $events,
        private EventPresentationFactory $presentations,
        private PublicationTocSourceAccess $sourceAccess,
    ) {}

    /**
     * @param list<string> $readerMutedPubkeys
     * @return array{
     *     eventId: string,
     *     title: ?string,
     *     summary: ?string,
     *     content: string,
     *     createdAt: int,
     *     authorPubkey: string,
     *     identifier: string,
     *     sensitive: bool,
     *     warningReason: ?string
     * }
     */
    public function fromAuthorizedIndexOccurrence(
        Event $source,
        int $position,
        string $resolvedTargetEventId,
        string $resolvedCoordinate,
        array $readerMutedPubkeys,
    ): array {
        $resolvedTargetEventId = strtolower($resolvedTargetEventId);
        if ($source->getKind() !== KindsEnum::PUBLICATION_INDEX->value
            || $position < 0
            || preg_match('/^[0-9a-f]{64}$/', $resolvedTargetEventId) !== 1) {
            throw new NotFoundHttpException();
        }

        $reference = null;
        foreach ($this->presentations->fromAuthorizedEvent($source)->references as $candidate) {
            if ($candidate->position === $position) {
                $reference = $candidate;
                break;
            }
        }
        if (!$reference instanceof EventReference
            || $reference->sourceEventId !== strtolower($source->getId())
            || $reference->role !== EventReferenceRole::Content
            || $reference->type->value !== 'address'
            || $reference->targetKind !== KindsEnum::PUBLICATION_CONTENT->value
            || $reference->target === null
            || $reference->targetPubkey === null
            || $reference->targetIdentifier === null
            || $reference->target !== $resolvedCoordinate
            || ($reference->versionEventId !== null && $reference->versionEventId !== $resolvedTargetEventId)
            || $reference->status !== EventReferenceStatus::Unresolved) {
            throw new NotFoundHttpException();
        }

        $targets = $this->events->findWorkspacePreviewTargets(
            $reference->versionEventId === null ? [$reference->target] : [],
            $reference->versionEventId === null ? [] : [$reference->versionEventId],
        );
        $metadataTarget = $reference->versionEventId === null
            ? ($targets['coordinates'][$reference->target] ?? null)
            : ($targets['eventIds'][$reference->versionEventId] ?? null);
        if (!$metadataTarget instanceof Event
            || strtolower($metadataTarget->getId()) !== $resolvedTargetEventId
            || !$this->matchesAddress($metadataTarget, $reference)) {
            throw new NotFoundHttpException();
        }

        // The metadata-only lookup is enough to authorize identity, mute,
        // suppression, hidden-coordinate, and tombstone policy before selecting
        // the chapter body.
        $coordinate = $this->coordinate($metadataTarget);
        $this->sourceAccess->assertTargetReadable(
            $metadataTarget->getPubkey(),
            $coordinate,
            $metadataTarget->getId(),
            $metadataTarget->getCreatedAt(),
            $readerMutedPubkeys,
        );

        $chapter = $this->events->findById(strtolower($metadataTarget->getId()));
        if (!$chapter instanceof Event
            || strtolower($chapter->getId()) !== strtolower($metadataTarget->getId())
            || $chapter->getCreatedAt() !== $metadataTarget->getCreatedAt()
            || !$this->matchesAddress($chapter, $reference)) {
            throw new NotFoundHttpException();
        }

        $presentation = $this->presentations->fromAuthorizedEvent($chapter);
        $sensitive = EventPresentation::hasSensitiveTagEvidence($chapter->getTags());
        $title = $sensitive ? null : $presentation->title;
        $summary = $sensitive ? null : $this->summaryTag($chapter);

        return [
            'eventId' => strtolower($chapter->getId()),
            'title' => $title,
            'summary' => $summary,
            'content' => $chapter->getContent(),
            'createdAt' => $chapter->getCreatedAt(),
            'authorPubkey' => strtolower($chapter->getPubkey()),
            'identifier' => $reference->targetIdentifier,
            'sensitive' => $sensitive,
            'warningReason' => $sensitive ? $presentation->contentWarningReason : null,
        ];
    }

    private function matchesAddress(Event $event, EventReference $reference): bool
    {
        return $event->getKind() === KindsEnum::PUBLICATION_CONTENT->value
            && strtolower($event->getPubkey()) === strtolower($reference->targetPubkey ?? '')
            && $this->dTag($event) === $reference->targetIdentifier
            && ($reference->versionEventId === null
                || strtolower($event->getId()) === $reference->versionEventId);
    }

    private function coordinate(Event $event): string
    {
        return KindsEnum::PUBLICATION_CONTENT->value . ':'
            . strtolower($event->getPubkey()) . ':' . ($this->dTag($event) ?? '');
    }

    private function dTag(Event $event): ?string
    {
        foreach ($event->getTags() as $tag) {
            if (is_array($tag) && ($tag[0] ?? null) === 'd') {
                return is_string($tag[1] ?? null) ? $tag[1] : null;
            }
        }

        return $event->getDTag() ?? '';
    }

    private function summaryTag(Event $event): ?string
    {
        foreach ($event->getTags() as $tag) {
            if (is_array($tag) && ($tag[0] ?? null) === 'summary' && is_string($tag[1] ?? null)) {
                return mb_substr($tag[1], 0, 500);
            }
        }

        return null;
    }
}
