<?php

declare(strict_types=1);

namespace App\Service\EventWorkspace;

use App\Entity\Event;
use App\Repository\DeletedEventRepository;
use App\Repository\EventRepository;
use App\Repository\HiddenCoordinateRepository;
use App\Service\Reader\ContentAuthorAccessPolicy;
use App\Service\UserMuteListService;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Server-side access checks for a locally mounted NKBIP-04 drive.
 * Drive roots and referenced directories are independently checked on every
 * request. Authorship is not an edit grant or a read restriction.
 */
final readonly class DriveMountAccess
{
    public function __construct(
        private EventRepository $events,
        private DeletedEventRepository $deletions,
        private HiddenCoordinateRepository $hiddenCoordinates,
        private ContentAuthorAccessPolicy $authors,
        private UserMuteListService $mutes,
        private LoggerInterface $logger,
    ) {}

    public function readableDrive(string $eventId, string $readerPubkey): ?Event
    {
        $event = $this->events->findOneBy([
            'id' => strtolower($eventId),
            'kind' => 30042,
        ]);

        return $event instanceof Event && $this->isReadableDrive($event, $readerPubkey)
            ? $event
            : null;
    }

    public function isReadableDrive(Event $event, string $readerPubkey): bool
    {
        return $event->getKind() === 30042 && $this->isReadableMountEvent($event, $readerPubkey);
    }

    /**
     * The public root shape check is shared with the batched inventory policy.
     * It does not authorize an event: author, deletion, hidden-coordinate and
     * reader-mute checks must still run before returning metadata.
     */
    public function hasReadableDriveStructure(Event $event): bool
    {
        return $event->getKind() === 30042 && $this->hasReadableMountStructure($event);
    }

    public function readableDirectory(string $eventId, string $readerPubkey): ?Event
    {
        $event = $this->events->findOneBy([
            'id' => strtolower($eventId),
            'kind' => 30045,
        ]);

        return $event instanceof Event
            && $event->getKind() === 30045
            && $this->isReadableMountEvent($event, $readerPubkey)
            ? $event
            : null;
    }

    /**
     * Revalidate every signed traversal edge against the currently readable
     * source tags so an old or forged URL cannot mount an unrelated directory.
     */
    public function referencesDirectory(Event $source, Event $child): bool
    {
        if (!in_array($source->getKind(), [30042, 30045], true)
            || $child->getKind() !== 30045) {
            return false;
        }
        $childId = strtolower($child->getId());
        $childCoordinate = '30045:' . strtolower($child->getPubkey()) . ':' . ($this->firstDTag($child) ?? '');

        foreach ($source->getTags() as $tag) {
            if (!is_array($tag)) {
                continue;
            }
            if ($source->getKind() === 30045 && ($tag[0] ?? null) === 'e'
                && is_string($tag[1] ?? null)
                && strtolower($tag[1]) === $childId) {
                return true;
            }
            if (($tag[0] ?? null) !== 'a' || !is_string($tag[1] ?? null)) {
                continue;
            }
            if (!$this->matchesDirectoryAddress($tag[1], $childCoordinate)) {
                continue;
            }
            if ($source->getKind() === 30045 && array_key_exists(3, $tag) && $tag[3] !== '') {
                if (!is_string($tag[3])
                    || preg_match('/^[0-9a-f]{64}$/iD', $tag[3]) !== 1
                    || strtolower($tag[3]) !== $childId) {
                    continue;
                }
            }

            return true;
        }

        return false;
    }

    private function matchesDirectoryAddress(string $address, string $coordinate): bool
    {
        $parts = explode(':', $address, 3);
        if (count($parts) !== 3
            || $parts[0] === ''
            || !ctype_digit($parts[0])
            || (int) $parts[0] !== 30045
            || preg_match('/^[0-9a-f]{64}$/iD', $parts[1]) !== 1) {
            return false;
        }

        return '30045:' . strtolower($parts[1]) . ':' . $parts[2] === $coordinate;
    }

    private function isReadableMountEvent(Event $event, string $readerPubkey): bool
    {
        $pubkey = strtolower($event->getPubkey());
        $readerPubkey = strtolower($readerPubkey);
        $dTag = $this->firstDTag($event);
        if (!$this->hasReadableMountStructure($event)) {
            return false;
        }

        try {
            $this->authors->assertReadable($pubkey);
        } catch (NotFoundHttpException) {
            // Author suppression is an expected visibility denial.
            return false;
        } catch (\Throwable) {
            $this->logPolicyFailure();

            return false;
        }

        try {
            $muted = array_map('strtolower', $this->mutes->getMutedPubkeysStrict($readerPubkey));
            if (in_array($pubkey, $muted, true)) {
                return false;
            }

            if ($this->deletions->isSuppressed(
                $event->getId(),
                $event->getKind(),
                $pubkey,
                $dTag,
                $event->getCreatedAt(),
            )) {
                return false;
            }

            $coordinate = $event->getKind() . ':' . $pubkey . ':' . $dTag;
            if (in_array($coordinate, $this->hiddenCoordinates->findHiddenCoordinates([$coordinate]), true)) {
                return false;
            }
        } catch (\Throwable) {
            $this->logPolicyFailure();

            return false;
        }

        return true;
    }

    private function hasReadableMountStructure(Event $event): bool
    {
        $isScoped = $event->getKind() === 30042
            ? EventWorkspaceScopePolicy::isScopedDriveRoot($event->getTags())
            : EventWorkspaceScopePolicy::isScopedDirectory($event->getTags());

        return $this->firstDTag($event) !== null
            && $event->getContent() === ''
            && !$isScoped
            && !\App\Dto\EventWorkspace\EventPresentation::hasSensitiveTagEvidence($event->getTags());
    }

    private function logPolicyFailure(): void
    {
        try {
            $this->logger->error('Drive mount access policy failed; denying access.');
        } catch (\Throwable) {
            // Logging failure must not change the fail-closed access decision.
        }
    }

    private function firstDTag(Event $event): ?string
    {
        foreach ($event->getTags() as $tag) {
            if (is_array($tag) && ($tag[0] ?? null) === 'd') {
                return is_string($tag[1] ?? null) ? $tag[1] : null;
            }
        }

        return null;
    }
}
