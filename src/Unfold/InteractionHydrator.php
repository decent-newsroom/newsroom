<?php

declare(strict_types=1);

namespace App\Unfold;

use App\Entity\Article;
use App\Entity\Event;
use App\Enum\EventStatusEnum;
use App\Repository\ArticleRepository;
use App\Repository\EventRepository;
use App\Service\Nostr\RelayRegistry;
use App\Service\Nostr\NostrEventVerifier;
use Doctrine\DBAL\Connection;
use DecentNewsroom\UnfoldBundle\Config\CategoryReference;
use DecentNewsroom\UnfoldBundle\Config\ContentReference;
use DecentNewsroom\UnfoldBundle\Content\ContentKindPolicy;
use DecentNewsroom\UnfoldBundle\Content\PostData;
use DecentNewsroom\UnfoldBundle\Contract\Comment;
use DecentNewsroom\UnfoldBundle\Contract\CommentProviderInterface;
use DecentNewsroom\UnfoldBundle\Contract\InteractionPage;
use DecentNewsroom\UnfoldBundle\Contract\InteractionState;
use DecentNewsroom\UnfoldBundle\Contract\InteractionTarget;
use DecentNewsroom\UnfoldBundle\Contract\NostrEvent;
use DecentNewsroom\UnfoldBundle\Contract\PublicationTreeLookupInterface;
use Psr\Log\LoggerInterface;

final readonly class InteractionHydrator
{
    private const THREAD_ITEM_LIMIT = 100;

    public function __construct(
        private CommentProviderInterface $comments,
        private ArticleRepository $articles,
        private EventRepository $events,
        private PublicationTreeLookupInterface $treeLookup,
        private RelayRegistry $relayRegistry,
        private LoggerInterface $logger,
        private ?Connection $connection = null,
        private ?NostrEventVerifier $verifier = null,
    ) {
    }

    public function target(string $publicationCoordinate, string $coordinate): ?InteractionTarget
    {
        try {
            $publicationCoordinate = CategoryReference::fromInput($publicationCoordinate)->coordinate;
            $reference = ContentReference::fromInput($coordinate);

            if (!$this->isPublicationMember($publicationCoordinate, $reference->coordinate)) {
                return null;
            }

            $selected = $this->latestRevisionCandidate($reference);
            if ($selected === null || !$selected['public']) {
                return null;
            }

            $post = $selected['source'] === 'article'
                ? $this->postFromArticle($selected['article'], $reference->coordinate)
                : PostData::fromEvent($selected['event']);

            $original = $selected['source'] === 'article'
                ? $this->originalFromArticle($selected['article'], $reference->coordinate)
                : $this->verifiedOriginal($selected['event']);

            $relayHint = $reference->relayHints[0] ?? $this->relayRegistry->getContentRelays()[0] ?? null;

            return new InteractionTarget($publicationCoordinate, $post, $original, $relayHint);
        } catch (\InvalidArgumentException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->error('Interaction target resolution failed', [
                'publication_coordinate' => $publicationCoordinate,
                'coordinate' => $coordinate,
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException('unfold_interactions.unavailable', 0, $e);
        }
    }

    public function thread(InteractionTarget $target, ?string $cursor = null): InteractionPage
    {
        try {
            $references = $this->threadReferences($target);
            $comments = $this->threadComments($references);
            $cursorTuple = $cursor === null ? null : $this->parseCursor($cursor);

            $commentMap = [];
            foreach ($comments as $comment) {
                if ($comment->kind === 1111) {
                    $commentMap[$comment->id] = $comment;
                }
            }

            $groups = $this->groupThreadItems($comments, $commentMap, $references);
            usort($groups, static function (array $left, array $right): int {
                return ($right['root_created_at'] <=> $left['root_created_at'])
                    ?: strcmp($right['root_id'], $left['root_id']);
            });

            $flattened = [];
            $keys = [];
            foreach ($groups as $group) {
                foreach ($group['items'] as $item) {
                    $flattened[] = $item;
                    $keys[] = [$group['root_created_at'], $group['root_id'], $item->createdAt, $item->id];
                }
            }
            $commentCount = count(array_filter($flattened, static fn (Comment $item): bool => $item->kind === 1111));
            $page = [];
            $pageKeys = [];
            foreach ($flattened as $index => $item) {
                $key = $keys[$index];
                if ($cursorTuple !== null) {
                    $rootOrder = ($key[0] <=> $cursorTuple[0]) ?: strcmp($key[1], $cursorTuple[1]);
                    $itemOrder = ($key[2] <=> $cursorTuple[2]) ?: strcmp($key[3], $cursorTuple[3]);
                    if ($rootOrder > 0 || ($rootOrder === 0 && $itemOrder <= 0)) {
                        continue;
                    }
                }
                $page[] = $item;
                $pageKeys[] = $key;
            }
            $nextCursor = count($page) > self::THREAD_ITEM_LIMIT
                ? implode(':', $pageKeys[self::THREAD_ITEM_LIMIT - 1]) : null;
            return new InteractionPage(array_slice($page, 0, self::THREAD_ITEM_LIMIT), $nextCursor, $commentCount);
        } catch (\InvalidArgumentException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->error('Interaction thread resolution failed', [
                'coordinate' => $target->post->coordinate,
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException('unfold_interactions.unavailable', 0, $e);
        }
    }

    public function parent(InteractionTarget $target, string $eventId): ?Comment
    {
        try {
            if (preg_match('/^[a-f0-9]{64}$/D', $eventId) !== 1) {
                return null;
            }

            $references = $this->threadReferences($target);
            $comments = $this->threadComments($references);
            $map = [];
            foreach ($comments as $comment) {
                $map[$comment->id] = $comment;
            }
            foreach ($comments as $comment) {
                if ($comment->kind !== 1111 || $comment->id !== $eventId) {
                    continue;
                }

                return $this->rootCommentId($comment, $map, $references, [], 99) !== null ? $comment : null;
            }

            return null;
        } catch (\Throwable $e) {
            $this->logger->error('Interaction parent lookup failed', [
                'coordinate' => $target->post->coordinate,
                'event_id' => $eventId,
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException('unfold_interactions.unavailable', 0, $e);
        }
    }

    public function state(InteractionTarget $target, ?string $readerPubkey = null): InteractionState
    {
        try {
            $references = $this->revisionReferences($target);
            $readerPubkey = $readerPubkey === null ? null : strtolower($readerPubkey);
            $likes = [];
            $reposts = [];

            if ($this->connection === null) {
                throw new \RuntimeException('Interaction query connection unavailable');
            }
            $params = [];
            $predicates = [];
            foreach ([['a', $target->post->coordinate], ...array_map(static fn (string $id): array => ['e', $id], $references)] as $index => $pair) {
                $params['ref' . $index] = json_encode([$pair], JSON_THROW_ON_ERROR);
                $predicates[] = 'tags @> CAST(:ref' . $index . ' AS jsonb)';
            }
            $rows = $this->connection->fetchAllAssociative(
                'SELECT id, pubkey, kind, content, tags, created_at, sig FROM event WHERE kind IN (7,16) AND (' . implode(' OR ', $predicates) . ') ORDER BY created_at DESC, id DESC LIMIT 10001',
                $params,
            );
            if (count($rows) > 10000) {
                throw new \RuntimeException('Interaction aggregate exceeds safe query bound');
            }
            foreach ($rows as $row) {
                    $kind = (int) $row['kind'];
                    $event = new Event();
                    $event->setKind($kind);
                    $event->setPubkey($row['pubkey']);
                    $event->setContent($row['content']);
                    $event->setTags(is_string($row['tags']) ? json_decode($row['tags'], true, 512, JSON_THROW_ON_ERROR) : $row['tags']);
                    if (!$event instanceof Event || !$this->matchesInteractionReference($event, $target->post->coordinate, $references)) {
                        continue;
                    }

                    $pubkey = strtolower($event->getPubkey());
                    if (preg_match('/^[a-f0-9]{64}$/D', $pubkey) !== 1) {
                        continue;
                    }
                    if ($kind === 7) {
                        if ($this->isPositiveReaction($event)) {
                            $likes[$pubkey] = true;
                        }
                    } else {
                        $reposts[$pubkey] = true;
                }
            }

            return new InteractionState(
                liked: $readerPubkey !== null && isset($likes[$readerPubkey]),
                reposted: $readerPubkey !== null && isset($reposts[$readerPubkey]),
                likes: count($likes),
                reposts: count($reposts),
            );
        } catch (\Throwable $e) {
            $this->logger->error('Interaction state resolution failed', [
                'coordinate' => $target->post->coordinate,
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException('unfold_interactions.unavailable', 0, $e);
        }
    }

    /**
     * @return array{0:int, 1:string, 2:int, 3:string}
     */
    private function parseCursor(string $cursor): array
    {
        if (preg_match('/^(\d+):([a-f0-9]{64}):(\d+):([a-f0-9]{64})$/D', $cursor, $matches) !== 1) {
            throw new \InvalidArgumentException('unfold_interactions.invalid');
        }

        return [(int) $matches[1], $matches[2], (int) $matches[3], $matches[4]];
    }

    private function isPublicationMember(string $publicationCoordinate, string $coordinate): bool
    {
        $queue = [[$publicationCoordinate, 0]];
        $seen = [];
        $edges = 0;
        while ($queue !== [] && count($seen) < 128) {
            [$indexCoordinate, $depth] = array_shift($queue);
            if (isset($seen[$indexCoordinate])) {
                continue;
            }
            $seen[$indexCoordinate] = true;
            [, $author, $identifier] = explode(':', $indexCoordinate, 3);
            $index = $this->events->findLatestIndexByIdentifier($author, $identifier);
            if ($index === null || $index->getKind() !== 30040 || $index->getPubkey() !== $author
                || preg_match('/^[a-f0-9]{64}$/D', $index->getId()) !== 1
                || preg_match('/^[a-f0-9]{128}$/D', $index->getSig()) !== 1
                || !$this->validTags($index->getTags())
                || $this->tagValues($index->getTags(), 'd') !== [$identifier]
                || ContentReference::isScoped($this->eventToDto($index))) {
                continue;
            }
            foreach ($index->getTags() as $tag) {
                if ($tag[0] !== 'a') {
                    continue;
                }
                if (++$edges > 1024) {
                    return false;
                }
                if (($tag[1] ?? null) === $coordinate) {
                    return true;
                }
                if ($depth < 5 && preg_match('/^30040:[a-f0-9]{64}:.+$/Ds', $tag[1] ?? '') === 1) {
                    $queue[] = [$tag[1], $depth + 1];
                }
            }
        }

        return false;
    }

    /**
     * @return array{source:string, timestamp:int, public:bool, article?:Article, event?:NostrEvent}|null
     */
    private function latestRevisionCandidate(ContentReference $reference): ?array
    {
        $candidates = [];

        $article = $this->articles->findByCoordinates([
            ['kind' => $reference->kind, 'pubkey' => $reference->pubkey, 'slug' => $reference->identifier],
        ])[$reference->coordinate] ?? null;
        if ($article instanceof Article) {
            $candidates[] = [
                'source' => 'article',
                'timestamp' => $article->getCreatedAt()?->getTimestamp() ?? 0,
                'public' => $this->isPublicArticle($article),
                'article' => $article,
            ];
        }

        $event = $this->events->findByCoordinates([$reference->coordinate])[$reference->coordinate] ?? null;
        if ($event instanceof Event) {
            $candidates[] = [
                'source' => 'event',
                'timestamp' => $event->getCreatedAt(),
                'public' => $reference->matches($this->eventToDto($event)) && $this->isPublicEvent($event),
                'event' => $this->eventToDto($event),
            ];
        }

        if ($candidates === []) {
            return null;
        }

        usort($candidates, static function (array $left, array $right): int {
            return ($right['timestamp'] <=> $left['timestamp'])
                ?: strcmp($left['source'], $right['source']);
        });

        return $candidates[0];
    }

    /**
     * @return list<string>
     */
    private function threadReferences(InteractionTarget $target): array
    {
        $references = [$target->post->coordinate];
        foreach ($this->revisionReferences($target) as $revisionId) {
            $references[] = $revisionId;
        }

        return array_values(array_unique($references));
    }

    /**
     * @return list<string>
     */
    public function revisionReferences(InteractionTarget $target): array
    {
        $reference = ContentReference::fromInput($target->post->coordinate);
        $ids = [$target->post->eventId];

        foreach ($this->articles->findBy(['kind' => $reference->kind, 'pubkey' => $reference->pubkey, 'slug' => $reference->identifier], ['createdAt' => 'DESC'], 40) as $article) {
            if ($article instanceof Article && $this->isPublicArticle($article) && ($article->getEventId() ?? '') !== '') {
                $ids[] = $article->getEventId();
            }
        }

        $eventRevisions = [];
        if ($this->connection !== null) {
            $rows = $this->connection->fetchAllAssociative(
                "SELECT id, pubkey, kind, content, tags, created_at, sig FROM event WHERE kind = :kind AND pubkey = :author AND tags @> CAST(:identifier AS jsonb) ORDER BY created_at DESC, id DESC LIMIT 40",
                ['kind' => $reference->kind, 'author' => $reference->pubkey, 'identifier' => json_encode([['d', $reference->identifier]], JSON_THROW_ON_ERROR)],
            );
            foreach ($rows as $row) {
                $event = new Event();
                $event->setId($row['id']);
                $event->setPubkey($row['pubkey']);
                $event->setKind((int) $row['kind']);
                $event->setContent($row['content']);
                $event->setTags(is_string($row['tags']) ? json_decode($row['tags'], true, 512, JSON_THROW_ON_ERROR) : $row['tags']);
                $event->setCreatedAt((int) $row['created_at']);
                $event->setSig($row['sig']);
                $eventRevisions[] = $event;
            }
        }
        foreach ($eventRevisions as $event) {
            if ($event instanceof Event && $this->isPublicEvent($event) && $reference->matches($this->eventToDto($event))) {
                $ids[] = $event->getId();
            }
        }

        return array_slice(array_values(array_unique(array_filter($ids, static fn (string $id): bool => preg_match('/^[a-f0-9]{64}$/D', $id) === 1))), 0, 40);
    }

    /**
     * @param list<string> $references
     * @return list<Comment>
     */
    private function threadComments(array $references): array
    {
        if ($this->connection !== null) {
            $params = [];
            $predicates = [];
            foreach ($references as $reference) {
                foreach (str_contains($reference, ':') ? ['A', 'a'] : ['E', 'e'] as $tag) {
                    $key = 'ref' . count($params);
                    $params[$key] = json_encode([[$tag, $reference]], JSON_THROW_ON_ERROR);
                    $predicates[] = 'tags @> CAST(:' . $key . ' AS jsonb)';
                }
            }
            // Every accepted NIP-22 reply carries a matching uppercase root,
            // so this target-bound window includes whole chains, not just roots.
            $rows = $this->connection->fetchAllAssociative(
                'SELECT id, pubkey, kind, content, tags, created_at FROM event WHERE kind IN (1111,9735) AND (' . implode(' OR ', $predicates) . ') ORDER BY created_at DESC, id DESC LIMIT 10001',
                $params,
            );
            if (count($rows) > 10000) {
                throw new \RuntimeException('Interaction thread exceeds safe query bound');
            }
            return array_map(static fn (array $row): Comment => new Comment(
                $row['id'], (int) $row['kind'], $row['pubkey'], $row['content'], (int) $row['created_at'],
                is_string($row['tags']) ? json_decode($row['tags'], true, 512, JSON_THROW_ON_ERROR) : $row['tags'],
            ), $rows);
        }
        $comments = [];
        foreach ($references as $reference) {
            foreach ($this->comments->findByCoordinate($reference) as $comment) {
                $comments[$comment->id] = $comment;
            }
        }

        return array_values($comments);
    }

    /**
     * @param list<Comment> $comments
     * @param array<string, Comment> $commentMap
     * @param list<string> $references
     * @return array<int, array{root_id:string, root_created_at:int, items:list<Comment>}>
     */
    private function groupThreadItems(array $comments, array $commentMap, array $references): array
    {
        $groups = [];

        foreach ($comments as $comment) {
            if ($comment->kind === 1111) {
                if (!$this->commentBelongsToThread($comment, $references)) {
                    continue;
                }

                $rootId = $this->rootCommentId($comment, $commentMap, $references);
                if ($rootId === null) {
                    continue;
                }

                $groups[$rootId]['root_id'] ??= $rootId;
                $groups[$rootId]['root_created_at'] ??= $commentMap[$rootId]->createdAt ?? $comment->createdAt;
                $groups[$rootId]['items'][$comment->id] = $comment;
                continue;
            }

            if ($comment->kind === 9735 && $this->commentBelongsToThread($comment, $references)) {
                $key = 'zap:' . $comment->id;
                $groups[$key] = [
                    'root_id' => $comment->id,
                    'root_created_at' => $comment->createdAt,
                    'items' => [$comment->id => $comment],
                ];
            }
        }

        foreach ($groups as &$group) {
            uasort($group['items'], static fn (Comment $left, Comment $right): int => ($left->createdAt <=> $right->createdAt) ?: strcmp($left->id, $right->id));
            $group['items'] = array_values($group['items']);
        }
        unset($group);

        return array_values($groups);
    }

    /**
     * @param list<string> $references
     */
    private function commentBelongsToThread(Comment $comment, array $references): bool
    {
        if (!$this->validTags($comment->tags) || $this->tagValues($comment->tags, 's') !== []) {
            return false;
        }
        if ($comment->kind === 9735) {
            // Zap e tags may name a comment while the a tag names its article.
            $addresses = $this->tagValues($comment->tags, 'a');
            $ids = $this->tagValues($comment->tags, 'e');
            return count($addresses) <= 1 && count($ids) <= 1
                && ($addresses === [] ? isset($ids[0]) && in_array($ids[0], $references, true) : in_array($addresses[0], $references, true));
        }
        $addresses = $this->tagValues($comment->tags, 'A');
        $ids = $this->tagValues($comment->tags, 'E');
        $kinds = $this->tagValues($comment->tags, 'K');
        $authors = $this->tagValues($comment->tags, 'P');
        [$kind, $author] = explode(':', $references[0], 3);
        return count($addresses) <= 1 && count($ids) <= 1 && ($addresses !== [] || $ids !== [])
            && ($addresses === [] || $addresses === [$references[0]])
            && ($ids === [] || in_array($ids[0], $references, true))
            && $kinds === [$kind] && $authors === [$author];
    }

    /**
     * @param array<string, Comment> $commentMap
     */
    private function rootCommentId(Comment $comment, array $commentMap, array $references, array $seen = [], int $depthLimit = 100): ?string
    {
        if (isset($seen[$comment->id]) || count($seen) >= $depthLimit || !$this->commentBelongsToThread($comment, $references)) {
            return null;
        }
        $seen[$comment->id] = true;

        $ids = $this->tagValues($comment->tags, 'e');
        $addresses = $this->tagValues($comment->tags, 'a');
        $kinds = $this->tagValues($comment->tags, 'k');
        $authors = $this->tagValues($comment->tags, 'p');
        if (count($ids) > 1 || count($addresses) > 1 || count($kinds) > 1) {
            return null;
        }
        [$rootKind, $rootAuthor] = explode(':', $references[0], 3);
        $parentId = $ids[0] ?? null;
        // Historical root-only comments and replies without k remain compatible,
        // but an unknown e reference is never promoted to a top-level comment.
        if ($kinds === ['1111'] || ($kinds === [] && $parentId !== null && !in_array($parentId, $references, true))) {
            $parent = $commentMap[$parentId ?? ''] ?? null;
            if ($addresses !== [] || !$parent instanceof Comment || $parent->kind !== 1111
                || !in_array($parent->pubkey, $authors, true)) {
                return null;
            }
            return $this->rootCommentId($parent, $commentMap, $references, $seen, $depthLimit);
        }
        if (($addresses !== [] && $addresses !== [$references[0]])
            || ($parentId !== null && !in_array($parentId, $references, true))
            || ($kinds !== [] && $kinds !== [$rootKind])
            || (($ids !== [] || $addresses !== [] || $kinds !== []) && !in_array($rootAuthor, $authors, true))) {
            return null;
        }
        return $comment->id;
    }

    private function isPositiveReaction(Event $event): bool
    {
        return $event->getContent() === '' || $event->getContent() === '+';
    }

    private function eventToDto(Event $event): NostrEvent
    {
        return new NostrEvent(
            id: $event->getId(),
            pubkey: strtolower($event->getPubkey()),
            kind: $event->getKind(),
            content: $event->getContent(),
            tags: $event->getTags(),
            createdAt: $event->getCreatedAt(),
            sig: $event->getSig(),
        );
    }

    private function isPublicArticle(Article $article): bool
    {
        $kind = $article->getKind()?->value;
        if ($kind === null || !ContentKindPolicy::supports($kind)) {
            return false;
        }

        if ($article->getEventStatus() !== EventStatusEnum::PUBLISHED || $article->getPublishedAt() === null) {
            return false;
        }

        $raw = $article->getRaw();
        if ($raw !== null) {
            try {
                $event = $this->articleRawToEvent($article, $raw);
                return $event->id === $article->getEventId()
                    && $event->pubkey === $article->getPubkey()
                    && $event->kind === $kind
                    && ContentReference::fromEvent($event)->identifier === $article->getSlug()
                    && !ContentReference::isScoped($event);
            } catch (\InvalidArgumentException) {
                return false;
            }
        }

        return true;
    }

    private function isPublicEvent(Event $event): bool
    {
        if (!ContentKindPolicy::supports($event->getKind())) {
            return false;
        }

        return $this->validTags($event->getTags()) && !ContentReference::isScoped($this->eventToDto($event));
    }

    private function postFromArticle(Article $article, string $coordinate): PostData
    {
        $raw = $article->getRaw();
        if ($raw !== null) {
            return PostData::fromEvent($this->articleRawToEvent($article, $raw));
        }

        return new PostData(
            slug: (string) ($article->getSlug() ?? ''),
            title: (string) ($article->getTitle() ?? $article->getSlug() ?? ''),
            summary: (string) ($article->getSummary() ?? ''),
            content: (string) ($article->getContent() ?? ''),
            image: $article->getImage(),
            publishedAt: $article->getPublishedAt()?->getTimestamp() ?? $article->getCreatedAt()?->getTimestamp() ?? 0,
            pubkey: strtolower((string) ($article->getPubkey() ?? '')),
            coordinate: $coordinate,
            kind: $article->getKind()?->value ?? 30023,
            tags: [['d', (string) ($article->getSlug() ?? '')]],
            eventId: (string) ($article->getEventId() ?? ''),
        );
    }

    private function originalFromArticle(Article $article, string $coordinate): ?NostrEvent
    {
        $raw = $article->getRaw();
        if ($raw === null) {
            return null;
        }

        $event = $this->articleRawToEvent($article, $raw);
        if (ContentReference::fromEvent($event)->coordinate !== $coordinate) {
            return null;
        }
        return $this->verifiedOriginal($event);
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function articleRawToEvent(Article $article, array $raw): NostrEvent
    {
        if (!is_string($raw['id'] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $raw['id']) !== 1
            || !is_string($raw['pubkey'] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $raw['pubkey']) !== 1
            || !is_int($raw['kind'] ?? null) || !is_int($raw['created_at'] ?? null)
            || !is_string($raw['content'] ?? null) || !is_string($raw['sig'] ?? null)
            || !is_array($raw['tags'] ?? null) || !$this->validTags($raw['tags'])) {
            throw new \InvalidArgumentException('Malformed stored article source');
        }
        return new NostrEvent(
            id: $raw['id'], pubkey: $raw['pubkey'], kind: $raw['kind'],
            content: $raw['content'], tags: $raw['tags'],
            createdAt: $raw['created_at'], sig: $raw['sig'],
        );
    }

    private function matchesInteractionReference(Event $event, string $coordinate, array $revisionIds): bool
    {
        $tags = $event->getTags();
        if (!$this->validTags($tags) || $this->tagValues($tags, 's') !== []) {
            return false;
        }
        $addresses = [...$this->tagValues($tags, 'a'), ...$this->tagValues($tags, 'A')];
        $ids = [...$this->tagValues($tags, 'e'), ...$this->tagValues($tags, 'E')];
        [$kind, $author] = explode(':', $coordinate, 3);
        $kinds = $this->tagValues($tags, 'k');
        $authors = $this->tagValues($tags, 'p');
        return count($addresses) <= 1 && count($ids) <= 1 && ($addresses !== [] || $ids !== [])
            && ($addresses === [] || $addresses === [$coordinate])
            && ($ids === [] || in_array($ids[0], $revisionIds, true))
            && ($kinds === [] || $kinds === [$kind])
            && ($authors === [] || $authors === [$author]);
    }

    private function validTags(array $tags): bool
    {
        if (!array_is_list($tags)) {
            return false;
        }
        foreach ($tags as $tag) {
            if (!is_array($tag) || !array_is_list($tag) || $tag === []) {
                return false;
            }
            foreach ($tag as $value) {
                if (!is_string($value)) {
                    return false;
                }
            }
        }
        return true;
    }

    private function tagValues(array $tags, string $name): array
    {
        $values = [];
        foreach ($tags as $tag) {
            if (($tag[0] ?? null) === $name) {
                $values[] = $tag[1] ?? '';
            }
        }
        return array_values(array_unique($values));
    }

    private function verifiedOriginal(NostrEvent $event): ?NostrEvent
    {
        if ($this->verifier === null || preg_match('/^[a-f0-9]{128}$/D', $event->sig) !== 1) {
            return null;
        }
        try {
            $source = $this->verifier->fromArray([
                'id' => $event->id, 'pubkey' => $event->pubkey, 'kind' => $event->kind,
                'content' => $event->content, 'tags' => $event->tags,
                'created_at' => $event->createdAt, 'sig' => $event->sig,
            ]);
        } catch (\InvalidArgumentException | \TypeError) {
            return null;
        }
        return $this->verifier->verify($source) ? $event : null;
    }
}
