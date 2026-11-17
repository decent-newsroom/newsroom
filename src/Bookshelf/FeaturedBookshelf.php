<?php

declare(strict_types=1);

namespace App\Bookshelf;

use App\Dto\EventWorkspace\EventPresentation;
use App\Dto\EventWorkspace\EventReference;
use App\Dto\EventWorkspace\EventReferenceRole;
use App\Dto\EventWorkspace\EventReferenceStatus;
use App\Dto\EventWorkspace\EventReferenceType;
use App\Repository\DeletedEventRepository;
use App\Repository\EventRepository;
use App\Repository\HiddenCoordinateRepository;
use App\Service\EventWorkspace\EventPresentationFactory;
use App\Service\EventWorkspace\EventWorkspaceScopePolicy;
use App\Service\Nostr\NostrNip19Service;
use App\Service\Reader\ContentAuthorAccessPolicy;
use DecentNewsroom\BookshelfBundle\Service\Mercury\Exception\MercuryApiException;
use DecentNewsroom\BookshelfBundle\Service\Mercury\MercuryApiClientInterface;
use DecentNewsroom\BookshelfBundle\Service\Mercury\Model\MercuryEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class FeaturedBookshelf
{
    public const PREVIEW_SIZE = 6;
    public const PAGE_SIZE = 50;

    public function __construct(
        #[Autowire('%bookshelf.featured_directory_naddr%')]
        private string $directoryNaddr,
        private NostrNip19Service $nip19,
        private EventRepository $events,
        private EventPresentationFactory $presentations,
        private MercuryApiClientInterface $api,
        private ContentAuthorAccessPolicy $authors,
        private DeletedEventRepository $deletions,
        private HiddenCoordinateRepository $hidden,
        private LoggerInterface $logger,
    ) {}

    /**
     * @param list<string> $mutedPubkeys
     * @return array{state: string, entries: list<array{status: string, book: ?EventPresentation}>, eventId: ?string, page: int, hasPrevious: bool, hasNext: bool}
     */
    public function page(array $mutedPubkeys = [], int $page = 1, ?string $eventId = null, bool $preview = false): array
    {
        if ($page < 1 || $page > intdiv(PHP_INT_MAX, self::PAGE_SIZE)) {
            throw new \InvalidArgumentException('Invalid featured directory page.');
        }
        $result = [
            'state' => 'unconfigured', 'entries' => [], 'eventId' => null,
            'page' => $page, 'hasPrevious' => $page > 1, 'hasNext' => false,
        ];
        $naddr = trim($this->directoryNaddr);
        if ($naddr === '') {
            return $result;
        }
        $result['state'] = 'unavailable';
        try {
            $address = str_starts_with(strtolower($naddr), 'naddr1') ? $this->nip19->decode($naddr) : [];
        } catch (\Exception|\ValueError $exception) {
            $this->logger->error('Invalid featured Bookshelf directory address.', ['exception' => $exception]);

            return $result;
        }
        if (($address['kind'] ?? null) !== 30045 || !is_string($address['pubkey'] ?? null)
            || preg_match('/^[0-9a-f]{64}$/D', $address['pubkey']) !== 1
            || !is_string($address['identifier'] ?? null)) {
            $this->logger->error('Featured Bookshelf address must identify a kind-30045 directory.');

            return $result;
        }
        $criteria = ['kind' => 30045, 'pubkey' => $address['pubkey'], 'dTag' => $address['identifier']];
        if ($eventId !== null) {
            $criteria['id'] = $eventId;
        }
        $source = $this->events->findOneBy($criteria, ['created_at' => 'DESC', 'id' => 'ASC']);
        if ($source === null) {
            return $result;
        }
        $muted = array_map('strtolower', $mutedPubkeys);
        if ($source->getKind() !== 30045 || $source->getPubkey() !== $address['pubkey']
            || $this->identifier($source->getTags()) !== $address['identifier']
            || ($eventId !== null && $source->getId() !== $eventId)
            || $source->getContent() !== ''
            || EventWorkspaceScopePolicy::isScopedDirectory($source->getTags())
            || !$this->readable($source->getId(), 30045, $source->getPubkey(), $source->getCreatedAt(), $source->getTags(), $muted)) {
            return $result;
        }
        $result['eventId'] = $source->getId();
        $references = array_values(array_filter(
            $this->presentations->references($source->getId(), 30045, $source->getTags()),
            static fn (EventReference $ref): bool => $ref->role === EventReferenceRole::Content
                && ($ref->type === EventReferenceType::EventId || $ref->targetKind === 30040 || $ref->target === null),
        ));
        $size = $preview ? self::PREVIEW_SIZE : self::PAGE_SIZE;
        $offset = $preview ? 0 : ($page - 1) * $size;
        $result['hasNext'] = count($references) > $offset + $size;
        $references = array_slice($references, $offset, $size);
        $result['state'] = 'available';
        if ($references === []) {
            return $result;
        }

        $ids = [];
        $coordinates = [];
        foreach ($references as $ref) {
            if ($ref->status !== EventReferenceStatus::Unresolved) {
                continue;
            }
            if ($ref->type === EventReferenceType::EventId || $ref->versionEventId !== null) {
                $ids[] = $ref->versionEventId ?? $ref->target;
            } elseif (trim($ref->targetIdentifier ?? '') !== '') {
                // Mercury's author helper drops empty d filters. Do not turn
                // an unsupported exact lookup into an author-wide scan.
                $coordinates[$ref->target] = [$ref->targetPubkey, $ref->targetIdentifier];
            }
        }
        $byId = [];
        $byCoordinate = [];
        try {
            $candidates = $ids === [] ? [] : $this->api->getPublicationEventsByIds(array_values(array_unique($ids)));
            // Query each exact coordinate, avoiding a cross-product of authors
            // and identifiers that can crowd the intended book out of a batch.
            foreach ($coordinates as [$pubkey, $identifier]) {
                array_push($candidates, ...$this->api->getPublicationsByAuthors([$pubkey], 50, [$identifier]));
            }
            foreach ($candidates as $event) {
                if ($event->kind !== 30040) {
                    continue;
                }
                $byId[$event->id] = $event;
                $identifier = $this->identifier($event->tags);
                if ($identifier === null) {
                    continue;
                }
                $coordinate = '30040:' . $event->pubkey . ':' . $identifier;
                $current = $byCoordinate[$coordinate] ?? null;
                if ($current === null || $event->createdAt > $current->createdAt
                    || ($event->createdAt === $current->createdAt && strcmp($event->id, $current->id) < 0)) {
                    $byCoordinate[$coordinate] = $event;
                }
            }
        } catch (MercuryApiException $exception) {
            $this->logger->warning('Featured Bookshelf metadata request failed.', ['exception' => $exception]);
            $result['state'] = 'failed';

            return $result;
        }
        foreach ($references as $ref) {
            $entry = ['status' => 'missing', 'book' => null];
            if ($ref->status !== EventReferenceStatus::Unresolved) {
                $entry['status'] = 'invalid';
            } else {
                $event = $ref->type === EventReferenceType::EventId || $ref->versionEventId !== null
                    ? ($byId[$ref->versionEventId ?? $ref->target] ?? null)
                    : ($byCoordinate[$ref->target] ?? null);
                if ($event instanceof MercuryEvent) {
                    $coordinate = '30040:' . $event->pubkey . ':' . $this->identifier($event->tags);
                    if ($ref->type === EventReferenceType::Address && $coordinate !== $ref->target) {
                        $entry['status'] = 'missing';
                    } elseif (EventWorkspaceScopePolicy::isScopedInventoryList(30040, $event->tags)
                        || $this->identifier($event->tags) === null
                        || !$this->readable($event->id, 30040, $event->pubkey, $event->createdAt, $event->tags, $muted)) {
                        $entry['status'] = 'restricted';
                    } else {
                        $entry = ['status' => 'available', 'book' => $this->presentations->fromAuthorizedFields(
                            $event->id, 30040, $event->pubkey, $event->createdAt, $event->tags, '',
                        )];
                    }
                }
            }
            $result['entries'][] = $entry;
        }

        return $result;
    }

    /** @param array<array-key, mixed> $tags */
    private function identifier(array $tags): ?string
    {
        foreach ($tags as $tag) {
            if (is_array($tag) && ($tag[0] ?? null) === 'd') {
                return is_string($tag[1] ?? null) ? $tag[1] : null;
            }
        }

        return null;
    }

    /** @param array<array-key, mixed> $tags
     *  @param list<string> $muted */
    private function readable(string $id, int $kind, string $pubkey, int $createdAt, array $tags, array $muted): bool
    {
        $identifier = $this->identifier($tags);

        return !in_array(strtolower($pubkey), $muted, true)
            && !EventPresentation::hasSensitiveTagEvidence($tags)
            && !$this->authors->isSuppressed($pubkey)
            && !$this->hidden->isHidden($kind . ':' . $pubkey . ':' . $identifier)
            && !$this->deletions->isSuppressed($id, $kind, $pubkey, $identifier, $createdAt);
    }
}
