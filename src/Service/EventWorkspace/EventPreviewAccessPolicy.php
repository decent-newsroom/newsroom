<?php

declare(strict_types=1);

namespace App\Service\EventWorkspace;

use App\Entity\Event;
use App\Entity\Article;
use App\Enum\KindsEnum;
use App\Repository\DeletedEventRepository;
use App\Repository\HiddenCoordinateRepository;
use App\Service\Graph\RecordIdentityService;
use App\Service\Nostr\NostrKeyService;
use App\Service\Reader\ArticleAccessService;
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
    public const INVENTORY_BATCH_SIZE = 128;
    /** Public embed kinds; longform additionally requires its exact Article projection. */
    public const PUBLIC_EMBED_KINDS = [1, 20, 21, 22, 30040, 30041, 30818];

    public function __construct(
        private ContentAuthorAccessPolicy $authors,
        private DeletedEventRepository $deletions,
        private HiddenCoordinateRepository $hiddenCoordinates,
        private RecordIdentityService $identities,
        private Security $security,
        private UserMuteListService $mutes,
        private NostrKeyService $keys,
        private LoggerInterface $logger,
        private ArticleAccessService $articleAccess,
    ) {}

    /** @return array<string, true> Lowercase muted pubkeys as a lookup set. */
    public function inventoryMuteSet(string $readerPubkey): array
    {
        try {
            return array_fill_keys(array_map('strtolower', $this->mutes->getMutedPubkeysStrict($readerPubkey)), true);
        } catch (\Throwable $exception) {
            $this->logger->error('Reading Nook inventory policy lookup failed.', [
                'policy_stage' => 'strict_mutes',
                'exception' => $exception,
            ]);
            throw new InventoryPolicyException('Inventory mute lookup failed.', 0, $exception);
        }
    }

    /**
     * Batch only the common identity policy. The caller must separately check
     * kind-specific scope, warning and drive structure before presenting data.
     * Unlike the single-preview API, lookup errors are propagated so an
     * incomplete inventory cannot be mistaken for an authorized empty one.
     *
     * @param list<Event> $events At most INVENTORY_BATCH_SIZE eligible events
     * @param array<string, true> $muted
     * @return list<Event> Readable events in original order
     */
    public function readableInventoryEvents(array $events, array $muted): array
    {
        if (count($events) > self::INVENTORY_BATCH_SIZE) {
            throw new \InvalidArgumentException('Inventory policy batch is too large.');
        }
        $candidates = $coordinates = $authors = [];
        foreach ($events as $event) {
            $author = strtolower($event->getPubkey());
            if (preg_match('/^[0-9a-f]{64}$/D', $author) !== 1 || isset($muted[$author])) {
                continue;
            }
            if ($this->identities->isParameterizedReplaceable($event->getKind())) {
                $dTag = '';
                foreach ($event->getTags() as $tag) {
                    if (is_array($tag) && ($tag[0] ?? null) === 'd') {
                        $dTag = is_string($tag[1] ?? null) ? $tag[1] : null;
                        break;
                    }
                }
                if ($dTag === null) {
                    continue;
                }
                $coordinates[strtolower($event->getId())] = $this->identities->deriveCoordinate($event->getKind(), $author, $dTag);
            }
            $authors[] = $author;
            $candidates[] = $event;
        }
        if ($candidates === []) {
            return [];
        }

        try {
            $suppressedAuthors = array_fill_keys($this->authors->suppressedPubkeys($authors), true);
            $suppressedEvents = array_fill_keys($this->deletions->findSuppressedEventIds($candidates), true);
            $hidden = array_fill_keys($this->hiddenCoordinates->findHiddenCoordinates(array_values($coordinates)), true);
        } catch (\Throwable $exception) {
            $this->logger->error('Reading Nook inventory policy lookup failed.', [
                'policy_stage' => 'batch_access',
                'exception' => $exception,
            ]);
            throw new InventoryPolicyException('Inventory access lookup failed.', 0, $exception);
        }

        return array_values(array_filter($candidates, static function (Event $event) use ($suppressedAuthors, $suppressedEvents, $hidden, $coordinates): bool {
            $id = strtolower($event->getId());
            $coordinate = $coordinates[$id] ?? null;

            return !isset($suppressedAuthors[strtolower($event->getPubkey())])
                && !isset($suppressedEvents[$id])
                && ($coordinate === null || !isset($hidden[$coordinate]));
        }));
    }

    public function isReadable(Event $event, ?UserInterface $viewer = null): bool
    {
        $eventId = $event->getId();
        $stage = 'event_fields';
        try {
            $author = strtolower($event->getPubkey());
            $dTag = null;
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

            return $this->isIdentityReadable(
                $eventId,
                $event->getKind(),
                $author,
                $dTag,
                $event->getCreatedAt(),
                $this->identities->isParameterizedReplaceable($event->getKind()),
                $stage,
                $viewer,
                authorAlreadyChecked: false,
            );
        } catch (\Throwable $exception) {
            $this->logFailure($eventId, $stage, $exception);
            return false;
        }
    }

    /**
     * An Article is a projection, not a signed Event. It can be safely
     * authorized without fabricating an Event when its projected identity is
     * complete and agrees with the requested kind/author/d-tag. The signed
     * first d must independently agree: the projection slug may have been
     * overwritten by a later duplicate during ingestion.
     */
    public function isArticleReadable(Article $article, string $requestedPubkey, string $requestedIdentifier): bool
    {
        $eventId = $article->getEventId() ?? '';
        $stage = 'article_projection_identity';
        try {
            $pubkey = strtolower((string) $article->getPubkey());
            $slug = $article->getSlug();
            $createdAt = $article->getCreatedAt();
            if ($article->getKind() !== KindsEnum::LONGFORM
                || preg_match('/^[0-9a-f]{64}$/i', $eventId) !== 1
                || preg_match('/^[0-9a-f]{64}$/i', $pubkey) !== 1
                || $slug === null
                || $slug !== $requestedIdentifier
                || !hash_equals(strtolower($requestedPubkey), $pubkey)
                || $createdAt === null) {
                return false;
            }

            $stage = 'article_raw_first_d_identity';
            $tags = $article->getRaw()['tags'] ?? null;
            $firstD = null;
            if (is_array($tags)) {
                foreach ($tags as $tag) {
                    if (is_array($tag) && ($tag[0] ?? null) === 'd') {
                        $firstD = $tag[1] ?? null;
                        break;
                    }
                }
            }
            // Explicit empty identifiers are valid; missing/malformed d tags
            // are not evidence of an empty identifier for Article previews.
            if (!is_string($firstD) || $firstD !== $requestedIdentifier) {
                return false;
            }

            $stage = 'author_suppression';
            $this->authors->assertReadable($pubkey);

            $stage = 'article_exclusive_entitlement';
            $viewer = $this->security->getUser();
            if ($article->isEssayistExclusive() && !$this->articleAccess->canViewEssayistExclusive($viewer, $article)) {
                return false;
            }

            return $this->isIdentityReadable(
                strtolower($eventId),
                KindsEnum::LONGFORM->value,
                $pubkey,
                $slug,
                $createdAt->getTimestamp(),
                true,
                $stage,
                $viewer,
                authorAlreadyChecked: true,
            );
        } catch (\Throwable $exception) {
            $this->logFailure($eventId, $stage, $exception);
            return false;
        }
    }

    private function isIdentityReadable(
        string $eventId,
        int $kind,
        string $author,
        ?string $dTag,
        int $createdAt,
        bool $hasCoordinate,
        string $stage,
        ?UserInterface $viewer = null,
        bool $authorAlreadyChecked = false,
    ): bool {
        try {
            if (!$authorAlreadyChecked) {
                $stage = 'author_suppression';
                $this->authors->assertReadable($author);
            }

            $coordinate = $hasCoordinate
                ? $this->identities->deriveCoordinate($kind, $author, $dTag)
                : null;
            $stage = 'hidden_coordinate';
            if ($coordinate !== null && $this->hiddenCoordinates->isHidden($coordinate)) {
                return false;
            }

            $stage = 'nip09_tombstone';
            if ($this->deletions->isSuppressed($eventId, $kind, $author, $dTag, $createdAt)) {
                return false;
            }

            $viewer ??= $this->security->getUser();
            if ($viewer instanceof UserInterface) {
                $stage = 'viewer_identity';
                $viewerPubkey = $this->keys->convertToHex($viewer->getUserIdentifier());
                $stage = 'strict_nip51_mute_list';
                $mutedPubkeys = array_map('strtolower', $this->mutes->getMutedPubkeysStrict($viewerPubkey));
                if (in_array(strtolower($author), $mutedPubkeys, true)) {
                    return false;
                }
            }

            return true;
        } catch (NotFoundHttpException) {
            // Author suppression is an expected visibility denial.
            return false;
        } catch (\Throwable $exception) {
            $this->logFailure($eventId, $stage, $exception);

            return false;
        }
    }

    private function logFailure(string $eventId, string $stage, \Throwable $exception): void
    {
        $this->logger->error('Event preview access policy failed; denying preview.', [
            'event_id' => $eventId,
            'policy_stage' => $stage,
            'exception_class' => $exception::class,
            'exception_message' => $exception->getMessage(),
        ]);
    }
}
