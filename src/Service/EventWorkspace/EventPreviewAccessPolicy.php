<?php

declare(strict_types=1);

namespace App\Service\EventWorkspace;

use App\Entity\Event;
use App\Repository\DeletedEventRepository;
use App\Repository\HiddenCoordinateRepository;
use App\Service\Graph\RecordIdentityService;
use App\Service\Nostr\NostrKeyService;
use App\Service\Reader\ContentAuthorAccessPolicy;
use App\Service\UserMuteListService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Applies the existing public event visibility policies before an API preview
 * exposes an Event presentation. Any policy/read failure denies the preview.
 */
final readonly class EventPreviewAccessPolicy
{
    public function __construct(
        private ContentAuthorAccessPolicy $authors,
        private DeletedEventRepository $deletions,
        private HiddenCoordinateRepository $hiddenCoordinates,
        private RecordIdentityService $identities,
        private Security $security,
        private UserMuteListService $mutes,
        private NostrKeyService $keys,
        private LoggerInterface $logger,
    ) {}

    public function isReadable(Event $event): bool
    {
        $stage = 'event_fields';
        try {
            $author = strtolower($event->getPubkey());
            $stage = 'author_suppression';
            $this->authors->assertReadable($author);

            $dTag = null;
            $coordinate = null;
            $stage = 'coordinate_resolution';
            if ($this->identities->isParameterizedReplaceable($event->getKind())) {
                $hasDTag = false;
                foreach ($event->getTags() as $tag) {
                    if (!is_array($tag) || ($tag[0] ?? null) !== 'd') {
                        continue;
                    }
                    $hasDTag = true;
                    // Match the presentation identity rule: only the first d
                    // tag participates, and a malformed value is not replaced
                    // by a later occurrence.
                    if (is_string($tag[1] ?? null)) {
                        $dTag = $tag[1];
                    }
                    break;
                }
                if (!$hasDTag) {
                    $dTag = '';
                }
                if ($dTag !== null) {
                    $coordinate = $this->identities->deriveCoordinate(
                        $event->getKind(),
                        $author,
                        $dTag,
                    );
                }
            }

            $stage = 'hidden_coordinate';
            if ($coordinate !== null && $this->hiddenCoordinates->isHidden($coordinate)) {
                return false;
            }
            $stage = 'nip09_tombstone';
            if ($this->deletions->isSuppressed(
                $event->getId(),
                $event->getKind(),
                $author,
                $dTag,
                $event->getCreatedAt(),
            )) {
                return false;
            }

            $viewer = $this->security->getUser();
            if ($viewer instanceof UserInterface) {
                $stage = 'viewer_identity';
                $viewerPubkey = $this->keys->convertToHex($viewer->getUserIdentifier());
                $stage = 'strict_nip51_mute_list';
                $mutedPubkeys = array_map('strtolower', $this->mutes->getMutedPubkeysStrict($viewerPubkey));
                if (in_array($author, $mutedPubkeys, true)) {
                    return false;
                }
            }

            return true;
        } catch (NotFoundHttpException) {
            // Author suppression is an expected visibility denial.
            return false;
        } catch (\Throwable $exception) {
            // Policy/database/identity failures must never expose event data.
            $this->logger->error('Event preview access policy failed; denying preview.', [
                'event_id' => $event->getId(),
                'policy_stage' => $stage,
                'exception_class' => $exception::class,
                'exception_message' => $exception->getMessage(),
            ]);

            return false;
        }
    }
}
