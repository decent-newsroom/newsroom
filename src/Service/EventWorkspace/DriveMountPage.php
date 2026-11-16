<?php

declare(strict_types=1);

namespace App\Service\EventWorkspace;

use App\Dto\EventWorkspace\EventReference;
use App\Dto\EventWorkspace\EventReferenceRole;
use App\Dto\EventWorkspace\EventReferenceType;
use App\Dto\EventWorkspace\EventBodyFormat;
use App\Dto\EventWorkspace\EventWorkspaceIdentity;
use App\Dto\EventWorkspace\EventWorkspaceResolutionResult;
use App\Dto\EventWorkspace\EventWorkspaceResolutionStatus;
use App\Entity\Event;
use App\Enum\KindsEnum;

/**
 * One local page of ordered drive/directory occurrences. It never follows a
 * relay hint or loads bodies for targets.
 */
final readonly class DriveMountPage
{
    public const PAGE_SIZE = 50;
    public const MAX_EXPANSION_DEPTH = 6;

    public function __construct(
        private EventWorkspaceSourceAdapter $sources,
        private EventPresentationFactory $presentations,
        private TargetPreviewResolver $targets,
        private PublicationTocTraversalSigner $signer,
        private DriveMountEventOpenSigner $openSigner,
        private ?DriveMountEventAccessInterface $openAccess = null,
    ) {}

    /**
     * @param list<string> $ancestorPath Path starts with the authorized drive ID.
     * @return array<string, mixed>
     */
    public function fromAuthorizedEvent(
        Event $event,
        int $page = 1,
        array $ancestorPath = [],
        ?string $occurrenceId = null,
        ?string $driveId = null,
        ?string $readerPubkey = null,
    ): array {
        if (!in_array($event->getKind(), [KindsEnum::DRIVE->value, KindsEnum::DIRECTORY->value], true)
            || $page < 1
            || count($ancestorPath) > self::MAX_EXPANSION_DEPTH) {
            throw new \InvalidArgumentException('Drive pages require a drive/directory and a bounded positive page.');
        }
        $isDrive = $event->getKind() === KindsEnum::DRIVE->value;
        $source = $isDrive
            ? $this->sources->fromNkbipDrive($event)
            : $this->sources->fromBookshelfDirectory($event);
        $effectiveDriveId = strtolower($driveId ?? $event->getId());
        $eventPath = $isDrive ? [$effectiveDriveId] : [...$ancestorPath, strtolower($event->getId())];
        $result = [
            'eventId' => strtolower($event->getId()),
            'driveId' => $effectiveDriveId,
            'isDrive' => $isDrive,
            'page' => $page,
            'start' => 1,
            'path' => $eventPath,
            'occurrenceId' => $occurrenceId,
            'entries' => [],
            'hasPrevious' => $page > 1,
            'hasNext' => false,
            'pageSignatures' => ['previous' => null, 'next' => null],
        ];
        if (($page - 1) > intdiv(PHP_INT_MAX - 1, self::PAGE_SIZE)) {
            return $result;
        }

        $offset = ($page - 1) * self::PAGE_SIZE;
        $result['start'] = $offset + 1;
        $tags = array_values($source->getRawTags() ?? []);
        $index = 0;
        foreach ($this->presentations->fromAuthorizedEvent($event)->references as $reference) {
            if ($reference->role !== EventReferenceRole::Content
                || ($isDrive && $reference->tagName !== 'a')) {
                continue;
            }
            if ($index++ < $offset) {
                continue;
            }
            if (count($result['entries']) === self::PAGE_SIZE) {
                $result['hasNext'] = true;
                break;
            }

            $tag = $tags[$reference->position] ?? [];
            $hasPin = !$isDrive
                && $reference->tagName === 'a'
                && array_key_exists(3, $tag)
                && $tag[3] !== '';
            $result['entries'][] = [
                'reference' => $reference,
                'rawVersionPin' => $hasPin && is_string($tag[3] ?? null) ? $tag[3] : null,
                'invalidVersionPin' => !$isDrive && $hasPin && $reference->versionEventId === null,
                'targetResolution' => null,
                'directory' => null,
                'cycle' => false,
                'depthLimited' => false,
                'nestedSignature' => null,
            ];
        }

        $directoryReferences = [];
        $generalReferences = [];
        $invalidDirectoryPins = [];
        foreach ($result['entries'] as $entry) {
            /** @var EventReference $reference */
            $reference = $entry['reference'];
            if ($isDrive
                || (!$isDrive && $reference->targetKind === KindsEnum::DIRECTORY->value)
                || (!$isDrive && $reference->type === EventReferenceType::EventId)) {
                $directoryReferences[] = $reference;
                if ($entry['invalidVersionPin']) {
                    $invalidDirectoryPins[] = $reference->position;
                }
            }
            if (!$isDrive
                && ($reference->targetKind !== KindsEnum::DIRECTORY->value
                    || $reference->type === EventReferenceType::EventId)) {
                $generalReferences[] = $reference;
            }
        }
        $directoryResults = $this->targets->resolveDirectoryPage($directoryReferences, $invalidDirectoryPins);
        $generalResults = $this->targets->resolvePage($generalReferences);
        $articleTargets = $readerPubkey === null || $this->openAccess === null
            ? []
            : $this->openAccess->readableArticleTargets(array_column($result['entries'], 'reference'));

        foreach ($result['entries'] as &$entry) {
            /** @var EventReference $reference */
            $reference = $entry['reference'];
            $resolution = $directoryResults[$reference->position] ?? $generalResults[$reference->position] ?? null;
            if ($resolution !== null
                && $resolution->status->value !== 'available'
                && $reference->type === EventReferenceType::EventId) {
                $resolution = $generalResults[$reference->position] ?? $resolution;
            }
            if ($resolution !== null
                && !in_array($resolution->status, [
                    EventWorkspaceResolutionStatus::Available,
                    EventWorkspaceResolutionStatus::Invalid,
                    EventWorkspaceResolutionStatus::Unavailable,
                ], true)) {
                // In a mounted drive, absent and unauthorized targets must
                // have the same externally visible status.
                $resolution = EventWorkspaceResolutionResult::unavailable($resolution->identity);
            }
            $articleTarget = $articleTargets[$reference->position] ?? null;
            if ($articleTarget instanceof Event) {
                $identity = match ($reference->type) {
                    EventReferenceType::EventId => EventWorkspaceIdentity::eventId($reference->target ?? ''),
                    EventReferenceType::Address => EventWorkspaceIdentity::address(
                        $reference->target ?? '',
                        $reference->versionEventId,
                    ),
                    default => null,
                };
                if ($identity !== null) {
                    $resolution = EventWorkspaceResolutionResult::available(
                        $identity,
                        $this->presentations->fromAuthorizedTargetMetadata($articleTarget),
                    );
                }
            }
            $entry['targetResolution'] = $resolution;
            if (!$isDrive && $readerPubkey !== null) {
                $targetId = null;
                if ($resolution?->status === EventWorkspaceResolutionStatus::Available
                    && $resolution->presentation !== null
                    && in_array($resolution->presentation->kind, [1, 20, 21, 22, 30040, 30041], true)
                    && !$resolution->presentation->isSensitive
                    && ($resolution->presentation->bodyFormat !== EventBodyFormat::Opaque
                        || in_array($resolution->presentation->kind, [20, 21, 22], true)
                        || $resolution->presentation->kind === KindsEnum::PUBLICATION_INDEX->value)) {
                    $targetId = strtolower($resolution->presentation->eventId);
                } elseif ($articleTarget instanceof Event) {
                    $targetId = strtolower($articleTarget->getId());
                }
                $rawTag = $tags[$reference->position] ?? null;
                if ($targetId !== null && is_array($rawTag)) {
                    $openOccurrenceId = $this->openSigner->occurrenceId(
                        $effectiveDriveId,
                        $eventPath,
                        $targetId,
                        $reference->position,
                        $rawTag,
                    );
                    $entry['openEvent'] = [
                        'targetId' => $targetId,
                        'path' => $eventPath,
                        'position' => $reference->position,
                        'occurrenceId' => $openOccurrenceId,
                        'signature' => $this->openSigner->sign(
                            $effectiveDriveId,
                            $eventPath,
                            $targetId,
                            $reference->position,
                            $rawTag,
                            $openOccurrenceId,
                        ),
                    ];
                }
            }
            if ($resolution?->status->value !== 'available'
                || $resolution->presentation === null
                || $resolution->presentation->kind !== KindsEnum::DIRECTORY->value) {
                if (!$isDrive && $resolution?->status !== EventWorkspaceResolutionStatus::Available) {
                    $entry['targetUnavailable'] = true;
                }
                if ($resolution?->status !== EventWorkspaceResolutionStatus::Available
                    || $resolution->presentation === null
                    || $resolution->presentation->kind !== KindsEnum::DIRECTORY->value) {
                    continue;
                }
            }
            $targetId = strtolower($resolution->presentation->eventId);
            $entry['cycle'] = in_array($targetId, array_map('strtolower', $eventPath), true);
            $entry['depthLimited'] = !$entry['cycle'] && count($eventPath) >= self::MAX_EXPANSION_DEPTH;
            $entry['directory'] = [
                'eventId' => $targetId,
                'coordinate' => $resolution->presentation->coordinate,
            ];
            if (!$entry['cycle'] && !$entry['depthLimited'] && $resolution->presentation->coordinate !== null) {
                $childOccurrence = hash('sha256', $effectiveDriveId . "\0" . $event->getId()
                    . "\0" . $reference->position . "\0" . $targetId);
                $entry['nestedOccurrenceId'] = $childOccurrence;
                $entry['nestedPath'] = $eventPath;
                $entry['nestedSignature'] = $this->signer->sign(
                    $targetId,
                    1,
                    $eventPath,
                    $childOccurrence,
                    $resolution->presentation->coordinate,
                );
            }
        }
        unset($entry);

        if (!$isDrive && $occurrenceId !== null) {
            $coordinate = $this->coordinate($event);
            if ($coordinate !== null) {
                $result['pageSignatures']['previous'] = $page > 1
                    ? $this->signer->sign($event->getId(), $page - 1, $ancestorPath, $occurrenceId, $coordinate)
                    : null;
                $result['pageSignatures']['next'] = $result['hasNext'] && $page < PHP_INT_MAX
                    ? $this->signer->sign($event->getId(), $page + 1, $ancestorPath, $occurrenceId, $coordinate)
                    : null;
            }
        }

        return $result;
    }

    private function coordinate(Event $event): ?string
    {
        foreach ($event->getTags() as $tag) {
            if (is_array($tag) && ($tag[0] ?? null) === 'd' && is_string($tag[1] ?? null)) {
                return $event->getKind() . ':' . strtolower($event->getPubkey()) . ':' . $tag[1];
            }
        }

        return null;
    }
}
