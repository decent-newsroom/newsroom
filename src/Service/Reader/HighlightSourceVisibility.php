<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Dto\EventWorkspace\EventPresentation;
use App\Entity\Article;
use App\Entity\Event;
use App\Entity\Highlight;
use App\Enum\KindsEnum;
use App\Repository\ArticleRepository;
use App\Repository\EventRepository;
use App\Repository\HighlightRepository;
use App\Service\EventWorkspace\AuthoredArticlePreview;
use App\Service\EventWorkspace\EventPreviewAccessPolicy;
use App\Service\EventWorkspace\EventWorkspaceScopePolicy;
use App\Service\Nostr\NostrEventVerifier;
use App\Service\Nostr\NostrNip19Service;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Keeps guest-facing highlights tied to sources currently present locally.
 * The signed-in feed and the shared Redis snapshots remain unchanged.
 */
final readonly class HighlightSourceVisibility
{
    private const BATCH_SIZE = 128;

    public function __construct(
        private EventRepository $events,
        private HighlightRepository $highlights,
        private ArticleRepository $articles,
        private Security $security,
        private NostrNip19Service $nip19,
        private LoggerInterface $logger,
        private EventPreviewAccessPolicy $previewAccess,
        private AuthoredArticlePreview $articlePreviews,
        private NostrEventVerifier $eventVerifier,
    ) {}

    public function isAnonymous(): bool
    {
        return $this->security->getUser() === null;
    }

    /**
     * @param array<int, array<string, mixed>|\stdClass> $items
     * @return list<array<string, mixed>|\stdClass>
     */
    public function filter(array $items): array
    {
        if (!$this->isAnonymous() || $items === []) {
            return $items;
        }

        $ids = [];
        foreach ($items as $item) {
            $data = (array) $item;
            $id = $this->eventId($data['id'] ?? null);
            if ($id !== null) {
                $ids[$id] = $id;
            }
        }

        $tagsById = [];
        foreach (array_chunk(array_values($ids), self::BATCH_SIZE) as $chunk) {
            foreach ($this->events->findByIds($chunk) as $event) {
                $id = $this->eventId($event->getId());
                if ($id !== null && isset($ids[$id]) && $event->getKind() === KindsEnum::HIGHLIGHTS->value) {
                    $tagsById[$id] = $event->getTags();
                }
            }
        }

        $missing = array_values(array_diff($ids, array_keys($tagsById)));
        foreach (array_chunk($missing, self::BATCH_SIZE) as $chunk) {
            foreach ($this->highlights->findBy(['eventId' => $chunk]) as $highlight) {
                if (!$highlight instanceof Highlight) {
                    continue;
                }
                $id = $this->eventId($highlight->getEventId());
                $tags = $highlight->getRawEvent()['tags'] ?? null;
                if ($id !== null && isset($ids[$id]) && is_array($tags)) {
                    $tagsById[$id] = $tags;
                }
            }
        }

        $candidates = [];
        foreach ($items as $item) {
            $data = (array) $item;
            $id = $this->eventId($data['id'] ?? null);
            if ($id === null || !isset($tagsById[$id])) {
                continue;
            }
            $reference = $this->sourceReference($tagsById[$id], $data['article_ref'] ?? null);
            if ($reference !== null) {
                $candidates[] = ['item' => $item, 'reference' => $reference];
            }
        }

        $available = $this->availableReferences(array_column($candidates, 'reference'));
        $result = [];
        foreach ($candidates as $candidate) {
            $reference = $candidate['reference'];
            if (!isset($available[$reference['type'] . ':' . $reference['value']])) {
                continue;
            }

            $item = $this->withSourceReference($candidate['item'], $reference);
            if ($item !== null) {
                $result[] = $item;
            }
        }

        return $result;
    }

    /**
     * Check the source used by the direct highlight-event page.
     *
     * @param array<array-key, mixed> $tags
     */
    public function isSourceAvailable(array $tags): bool
    {
        return $this->prepareEventSource($tags) !== null;
    }

    /**
     * Prepare the reference displayed by a direct highlight-event page.
     * An empty array leaves the signed-in page unchanged; null denies a guest.
     *
     * @param array<array-key, mixed> $tags
     * @return array<string, mixed>|null
     */
    public function prepareEventSource(array $tags): ?array
    {
        if (!$this->isAnonymous()) {
            return [];
        }

        $firstAddress = null;
        foreach ($tags as $tag) {
            if (is_array($tag) && in_array($tag[0] ?? null, ['a', 'A'], true) && isset($tag[1])) {
                $firstAddress = $tag[1];
                break;
            }
        }
        $reference = $this->sourceReference($tags, $firstAddress, requirePreferredAddress: true);
        if ($reference === null) {
            return null;
        }

        if (!isset($this->availableReferences([$reference])[$reference['type'] . ':' . $reference['value']])) {
            return null;
        }

        $source = $this->withSourceReference([], $reference);

        return is_array($source) ? $source : null;
    }

    /**
     * @param array<array-key, mixed> $tags
     * @return array{type: 'event'|'coordinate', value: string, relays?: list<string>}|null
     */
    private function sourceReference(array $tags, mixed $preferredAddress, bool $requirePreferredAddress = false): ?array
    {
        foreach ($tags as $tag) {
            if (is_array($tag) && ($tag[0] ?? null) === 'e') {
                $id = $this->eventId($tag[1] ?? null);
                if ($id !== null) {
                    return ['type' => 'event', 'value' => $id];
                }
            }
        }

        $preferred = $this->coordinate($preferredAddress);
        if ($requirePreferredAddress && $preferred === null) {
            return null;
        }

        $addresses = [];
        foreach ($tags as $tag) {
            if (!is_array($tag) || !in_array($tag[0] ?? null, ['a', 'A'], true)) {
                continue;
            }
            $coordinate = $this->coordinate($tag[1] ?? null);
            if ($coordinate === null) {
                continue;
            }
            if (!isset($addresses[$coordinate])) {
                $addresses[$coordinate] = [
                    'type' => 'coordinate',
                    'value' => $coordinate,
                    'relays' => [],
                ];
            }
            $relay = $tag[2] ?? null;
            if (is_string($relay) && preg_match('#^wss?://#i', $relay) === 1
                && !in_array($relay, $addresses[$coordinate]['relays'], true)) {
                $addresses[$coordinate]['relays'][] = $relay;
            }
        }

        if ($preferred !== null && isset($addresses[$preferred])) {
            return $addresses[$preferred];
        }
        if ($requirePreferredAddress) {
            return null;
        }

        return $addresses === [] ? null : reset($addresses);
    }

    /**
     * @param list<array{type: 'event'|'coordinate', value: string, relays?: list<string>}> $references
     * @return array<string, true>
     */
    private function availableReferences(array $references): array
    {
        $unique = [];
        foreach ($references as $reference) {
            $unique[$reference['type'] . ':' . $reference['value']] = $reference;
        }

        $available = [];
        $missingArticles = [];
        foreach (array_chunk(array_values($unique), self::BATCH_SIZE) as $chunk) {
            $eventIds = $coordinates = [];
            foreach ($chunk as $reference) {
                if ($reference['type'] === 'event') {
                    $eventIds[] = $reference['value'];
                } else {
                    $coordinates[] = $reference['value'];
                }
            }
            $targets = $this->events->findHighlightSourceTargets($coordinates, $eventIds);
            $candidates = $sourceEvents = [];
            foreach ($chunk as $reference) {
                $key = $reference['type'] . ':' . $reference['value'];
                $event = $reference['type'] === 'event'
                    ? ($targets['eventIds'][$reference['value']] ?? null)
                    : ($targets['coordinates'][$reference['value']] ?? null);
                if (!$event instanceof Event) {
                    if ($reference['type'] === 'coordinate') {
                        [$kind, $pubkey, $slug] = explode(':', $reference['value'], 3);
                        if ((int) $kind === KindsEnum::LONGFORM->value) {
                            $missingArticles[$reference['value']] = ['kind' => (int) $kind, 'pubkey' => $pubkey, 'slug' => $slug];
                        }
                    }
                    continue;
                }

                if (($reference['type'] === 'event' && $event->getId() !== $reference['value'])
                    || ($reference['type'] === 'coordinate' && !$this->matchesCoordinate($event, $reference['value']))
                    || !$this->isPublicSourceEvent($event)
                    || ($reference['type'] === 'coordinate'
                        && $event->getKind() === KindsEnum::PUBLICATION_CONTENT->value
                        && $this->firstIdentifier($event) === '')) {
                    continue;
                }

                $candidates[$key] = $event;
                $sourceEvents[strtolower($event->getId())] = $event;
            }

            $readable = [];
            foreach ($this->previewAccess->readableInventoryEvents(array_values($sourceEvents), []) as $event) {
                $readable[strtolower($event->getId())] = true;
            }
            $articleIds = [];
            foreach ($sourceEvents as $id => $event) {
                if (isset($readable[$id]) && $event->getKind() === KindsEnum::LONGFORM->value) {
                    $articleIds[] = $event->getId();
                }
            }
            $articles = $articleIds === [] ? [] : $this->articles->findHighlightSourcesByEventIds($articleIds);

            foreach ($candidates as $key => $event) {
                $id = strtolower($event->getId());
                if (!isset($readable[$id])) {
                    continue;
                }
                if ($event->getKind() === KindsEnum::LONGFORM->value) {
                    $identifier = $this->firstIdentifier($event);
                    if ($identifier === null || !$this->areReadableArticleProjections(
                        $articles[$event->getId()] ?? [],
                        $event->getId(),
                        strtolower($event->getPubkey()),
                        $identifier,
                        $event->getCreatedAt(),
                    )) {
                        continue;
                    }
                }
                $available[$key] = true;
            }
        }

        foreach (array_chunk($missingArticles, self::BATCH_SIZE, preserve_keys: true) as $chunk) {
            $articles = $this->articles->findByCoordinates(array_values($chunk));
            $articleIds = [];
            foreach ($articles as $article) {
                if ($article->getEventId() !== null) {
                    $articleIds[] = $article->getEventId();
                }
            }
            $projections = $articleIds === [] ? [] : $this->articles->findHighlightSourcesByEventIds($articleIds);
            foreach ($chunk as $coordinate => $identity) {
                $article = $articles[$coordinate] ?? null;
                $id = $article instanceof Article ? $article->getEventId() : null;
                if ($id !== null && $this->areReadableArticleProjections(
                    $projections[$id] ?? [], $id, $identity['pubkey'], $identity['slug'], verify: true,
                )) {
                    $available['coordinate:' . $coordinate] = true;
                }
            }
        }

        return $available;
    }

    private function isPublicSourceEvent(Event $event): bool
    {
        $kind = $event->getKind();

        return (in_array($kind, EventPreviewAccessPolicy::PUBLIC_EMBED_KINDS, true)
                || $kind === KindsEnum::LONGFORM->value)
            && !EventWorkspaceScopePolicy::isScopedTarget($kind, $event->getTags())
            && !EventPresentation::hasSensitiveTagEvidence($event->getTags());
    }

    private function isReadableArticle(Article $article, string $pubkey, string $identifier): bool
    {
        $tags = $article->getRaw()['tags'] ?? null;

        return is_array($tags)
            && !EventWorkspaceScopePolicy::isScopedTarget(KindsEnum::LONGFORM->value, $tags)
            && !EventPresentation::hasSensitiveTagEvidence($tags)
            && $this->previewAccess->isArticleReadable($article, $pubkey, $identifier);
    }

    /**
     * A preview's findOneBy(eventId) has no ordering, so every duplicate that
     * it could select must pass the same checks.
     *
     * @param list<Article> $articles
     */
    private function areReadableArticleProjections(
        array $articles,
        string $eventId,
        string $pubkey,
        string $identifier,
        ?int $createdAt = null,
        bool $verify = false,
    ): bool {
        if ($articles === []) {
            return false;
        }
        foreach ($articles as $article) {
            if ($article->getEventId() !== $eventId
                || !$this->isReadableArticle($article, $pubkey, $identifier)
                || ($createdAt !== null && $article->getCreatedAt()?->getTimestamp() !== $createdAt)
                || ($verify && !$this->hasVerifiedArticlePreview($article))) {
                return false;
            }
        }

        return true;
    }

    private function hasVerifiedArticlePreview(Article $article): bool
    {
        $raw = $article->getRaw();
        if (!is_array($raw)) {
            return false;
        }

        try {
            if (!$this->eventVerifier->verify($this->eventVerifier->fromArray($raw))) {
                return false;
            }

            return $this->articlePreviews->fromAuthorizedArticle($article, null) instanceof EventPresentation;
        } catch (\Throwable $exception) {
            $this->logger->warning('Unable to verify a local highlight source.', [
                'event_id' => $article->getEventId(),
                'exception' => $exception,
            ]);
            return false;
        }
    }

    private function matchesCoordinate(Event $event, string $coordinate): bool
    {
        [$kind, $pubkey, $identifier] = explode(':', $coordinate, 3);
        if ($event->getKind() !== (int) $kind || strtolower($event->getPubkey()) !== $pubkey) {
            return false;
        }
        return $this->firstIdentifier($event) === $identifier;
    }

    private function firstIdentifier(Event $event): ?string
    {
        /** @var array<array-key, mixed> $tags */
        $tags = $event->getTags();
        foreach ($tags as $tag) {
            if (is_array($tag) && ($tag[0] ?? null) === 'd') {
                return is_string($tag[1] ?? null) ? $tag[1] : null;
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed>|\stdClass $item
     * @param array{type: 'event'|'coordinate', value: string, relays?: list<string>} $reference
     * @return array<string, mixed>|\stdClass|null
     */
    private function withSourceReference(array|\stdClass $item, array $reference): array|\stdClass|null
    {
        $data = (array) $item;
        $data['event_ref'] = $reference['type'] === 'event' ? $reference['value'] : null;
        if ($reference['type'] === 'coordinate') {
            [$kind, $pubkey, $identifier] = explode(':', $reference['value'], 3);
            try {
                $naddr = $this->nip19->encodeAddr($pubkey, $identifier, (int) $kind, $reference['relays'] ?? []);
            } catch (\Throwable $exception) {
                $this->logger->warning('Unable to encode a local highlight source.', ['exception' => $exception]);
                return null;
            }
            if ($this->coordinate($data['article_ref'] ?? null) !== $reference['value']) {
                $data['article_title'] = null;
            }
            $data['article_ref'] = $reference['value'];
            $data['relayHints'] = $reference['relays'];
            $data['naddr'] = $naddr;
            $data['preview'] = null;
        }

        return is_array($item) ? $data : (object) $data;
    }

    private function eventId(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[0-9a-f]{64}$/iD', $value) === 1
            ? strtolower($value)
            : null;
    }

    private function coordinate(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $parts = explode(':', $value, 3);
        if (count($parts) !== 3 || !preg_match('/^3[0-9]{4}$/D', $parts[0])
            || preg_match('/^[0-9a-f]{64}$/iD', $parts[1]) !== 1) {
            return null;
        }

        return $parts[0] . ':' . strtolower($parts[1]) . ':' . $parts[2];
    }
}
