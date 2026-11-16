<?php

declare(strict_types=1);

namespace App\Service\EventWorkspace;

use App\Dto\EventWorkspace\EventReference;
use App\Dto\EventWorkspace\EventReferenceStatus;
use App\Dto\EventWorkspace\EventReferenceType;
use App\Dto\EventWorkspace\EventWorkspaceIdentity;
use App\Dto\EventWorkspace\EventWorkspaceIdentityType;
use App\Dto\EventWorkspace\EventWorkspaceResolutionResult;
use App\Entity\Event;
use App\Entity\User;
use App\Repository\DeletedEventRepository;
use App\Repository\EventRepository;
use App\Repository\HiddenCoordinateRepository;
use App\Service\Reader\ContentAuthorAccessPolicy;
use App\Service\UserMuteListService;
use App\Service\EventWorkspace\Metrics\WorkspaceMetrics;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * One DB-only page of target previews, not a general graph traversal.
 * The caller authorizes the source. Every target is independently filtered.
 */
final readonly class TargetPreviewResolver
{
    // Articles deliberately excluded until projection/member authorization can
    // be established safely for every revision, including Event-only records.
    private const PUBLIC_KINDS = [30040, 30041, 1, 20, 21, 22];

    public function __construct(
        private EventRepository $events,
        private DeletedEventRepository $deletions,
        private HiddenCoordinateRepository $hiddenCoordinates,
        private ContentAuthorAccessPolicy $authors,
        private EventPresentationFactory $presentations,
        private Security $security,
        private UserMuteListService $mutes,
        private ?WorkspaceMetrics $metrics = null,
    ) {}

    /**
     * Non-event references and invalid raw identities retain their source row
     * but have no fabricated target identity. Keys are source tag positions.
     *
     * @param list<EventReference> $references At most 50 visible occurrences
     * @param list<int> $invalidPinPositions
     * @return array<int, EventWorkspaceResolutionResult>
     */
    public function resolvePage(array $references, array $invalidPinPositions = []): array
    {
        return $this->observeResolution(fn (): array => $this->resolveForKinds($references, $invalidPinPositions, self::PUBLIC_KINDS, false));
    }

    /**
     * Resolve only local, empty-content NKBIP-04 directories. Keeping this
     * allowlist separate prevents generic previews from widening directory
     * access to arbitrary event kinds.
     *
     * @param list<EventReference> $references
     * @param list<int> $invalidPinPositions
     * @return array<int, EventWorkspaceResolutionResult>
     */
    public function resolveDirectoryPage(array $references, array $invalidPinPositions = []): array
    {
        return $this->observeResolution(fn (): array => $this->resolveForKinds($references, $invalidPinPositions, [30045], true));
    }

    /** @param callable(): array<int, EventWorkspaceResolutionResult> $resolve @return array<int, EventWorkspaceResolutionResult> */
    private function observeResolution(callable $resolve): array
    {
        try {
            $results = $resolve();
        } catch (\Throwable $error) {
            $this->metrics?->resolution('failed');
            throw $error;
        }
        foreach ($results as $result) {
            $this->metrics?->resolution($result->status->value);
        }

        return $results;
    }

    /**
     * @param list<EventReference> $references
     * @param list<int> $invalidPinPositions
     * @param list<int> $allowedKinds
     * @return array<int, EventWorkspaceResolutionResult>
     */
    private function resolveForKinds(
        array $references,
        array $invalidPinPositions,
        array $allowedKinds,
        bool $directoriesOnly,
    ): array
    {
        if (count($references) > 50) {
            throw new \InvalidArgumentException('Target previews are limited to one page.');
        }
        $requests = [];
        $results = [];
        $coordinates = [];
        $ids = [];
        foreach ($references as $reference) {
            if ($reference->target === null) {
                continue;
            }
            $identity = match ($reference->type) {
                EventReferenceType::EventId => EventWorkspaceIdentity::eventId($reference->target),
                EventReferenceType::Address => EventWorkspaceIdentity::address($reference->target, $reference->versionEventId),
                default => null,
            };
            if ($identity === null) {
                continue;
            }
            if (in_array($reference->position, $invalidPinPositions, true)) {
                $results[$reference->position] = EventWorkspaceResolutionResult::invalid($identity);
                continue;
            }
            if ($reference->status !== EventReferenceStatus::Unresolved) {
                continue;
            }
            if ($identity->type === EventWorkspaceIdentityType::Address
                && !in_array($reference->targetKind, $allowedKinds, true)) {
                $results[$reference->position] = EventWorkspaceResolutionResult::restricted($identity);
                continue;
            }
            $requests[$reference->position] = $identity;
            if ($identity->type === EventWorkspaceIdentityType::Address && $identity->versionEventId === null) {
                $coordinates[] = $identity->value;
            } else {
                $ids[] = $identity->versionEventId ?? $identity->value;
            }
        }
        if ($requests === []) {
            return $results;
        }

        $user = $this->security->getUser();
        $reader = $user instanceof User ? PublicKey::fromBech32($user->getUserIdentifier())?->toHex() : null;
        if ($reader === null) {
            foreach ($requests as $position => $identity) {
                $results[$position] = EventWorkspaceResolutionResult::restricted($identity);
            }

            return $results;
        }
        // Strict lookup propagates failures rather than silently dropping mutes.
        $muted = array_fill_keys(array_map('strtolower', $this->mutes->getMutedPubkeysStrict($reader)), true);
        $targets = $directoriesOnly
            ? $this->events->findWorkspaceDirectoryTargets(
                array_values(array_unique($coordinates)), array_values(array_unique($ids)),
            )
            : $this->events->findWorkspacePreviewTargets(
                array_values(array_unique($coordinates)), array_values(array_unique($ids)),
            );
        $byId = $targets['eventIds'];
        foreach ($targets['coordinates'] as $event) {
            $byId[strtolower($event->getId())] = $event;
        }
        $events = array_values($byId);
        $suppressedIds = array_fill_keys($this->deletions->findSuppressedEventIds($events), true);
        $suppressedAuthors = array_fill_keys($this->authors->suppressedPubkeys(array_map(
            static fn (Event $event): string => $event->getPubkey(), $events,
        )), true);
        $metadata = [];
        $targetCoordinates = [];
        foreach ($events as $event) {
            // Metadata is constructed only after public-kind and author policy.
            if (!in_array($event->getKind(), $allowedKinds, true)
                || isset($suppressedIds[strtolower($event->getId())])
                || isset($suppressedAuthors[strtolower($event->getPubkey())])
                || isset($muted[strtolower($event->getPubkey())])
                || ($event->getKind() === 30045
                    ? EventWorkspaceScopePolicy::isScopedDirectory($event->getTags())
                    : EventWorkspaceScopePolicy::isScopedTarget($event->getKind(), $event->getTags()))
                || ($directoriesOnly
                    && $event->getKind() === 30045
                    && !$this->hasStringDTag($event))) {
                continue;
            }
            $preview = $this->presentations->fromAuthorizedTargetMetadata($event);
            if ($directoriesOnly && $preview->isSensitive) {
                continue;
            }
            $metadata[strtolower($event->getId())] = $preview;
            if ($preview->coordinate !== null) {
                $targetCoordinates[] = $preview->coordinate;
            }
        }
        $hidden = array_fill_keys($this->hiddenCoordinates->findHiddenCoordinates($targetCoordinates), true);
        foreach ($requests as $position => $identity) {
            $event = $identity->type === EventWorkspaceIdentityType::Address && $identity->versionEventId === null
                ? ($targets['coordinates'][$identity->value] ?? null)
                : ($targets['eventIds'][$identity->versionEventId ?? $identity->value] ?? null);
            if ($event === null) {
                $results[$position] = EventWorkspaceResolutionResult::missing($identity);
                continue;
            }
            $preview = $metadata[strtolower($event->getId())] ?? null;
            if ($preview === null || ($preview->coordinate !== null && isset($hidden[$preview->coordinate]))) {
                $results[$position] = EventWorkspaceResolutionResult::restricted($identity);
                continue;
            }
            if ($identity->type === EventWorkspaceIdentityType::Address && $preview->coordinate !== $identity->value) {
                $results[$position] = EventWorkspaceResolutionResult::unavailable($identity);
                continue;
            }
            $results[$position] = EventWorkspaceResolutionResult::available($identity, $preview);
        }

        return $results;
    }

    /**
     * Directory targets use the first raw d tag as their replaceable identity.
     * An explicit empty string is valid; absent and malformed values are not.
     */
    private function hasStringDTag(Event $event): bool
    {
        foreach ($event->getTags() as $tag) {
            if (!is_array($tag) || ($tag[0] ?? null) !== 'd') {
                continue;
            }

            return is_string($tag[1] ?? null);
        }

        return false;
    }
}
