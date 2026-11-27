<?php

declare(strict_types=1);

namespace App\Bookshelf;

use App\Entity\Event;
use App\Repository\EventRepository;
use App\Repository\HiddenCoordinateRepository;
use App\Service\EventWorkspace\EventWorkspaceScopePolicy;
use App\Service\Nostr\NostrClient;
use App\Service\Reader\ContentAuthorAccessPolicy;
use DecentNewsroom\BookshelfBundle\Service\Mercury\Exception\MercuryApiException;
use DecentNewsroom\BookshelfBundle\Service\Mercury\MercuryApiClientInterface;
use DecentNewsroom\BookshelfBundle\Service\Mercury\Model\MercuryEvent;
use Psr\Cache\CacheException;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\NullAdapter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Reconstructs a continuous reader from the signed order of publication indexes. */
final readonly class BookshelfReaderContents
{
    private const MAX_ENTRIES = 500;
    public const PAGE_SIZE = 5;
    public const MAX_PAGES = self::MAX_ENTRIES / self::PAGE_SIZE;
    private const MAX_DEPTH = 6;
    private const MAX_RELAY_GROUPS = 20;
    private const MAX_API_PASSES = 3;
    private const EXACT_CACHE_TTL = 365 * 86400;
    private const COORDINATE_CACHE_TTL = 30 * 86400;

    public function __construct(
        private MercuryApiClientInterface $api,
        private NostrClient $nostr,
        private EventRepository $events,
        private ContentAuthorAccessPolicy $authors,
        private HiddenCoordinateRepository $hidden,
        private LoggerInterface $logger,
        private MercuryApiClientInterface $booksApi,
        #[Autowire(service: 'cache.app')]
        private CacheItemPoolInterface $cache = new NullAdapter(),
        private ?BookshelfMercuryCoordinates $mercuryCoordinates = null,
    ) {}

    public function source(string $id): ?object
    {
        $cached = $this->cachedEvent('event:' . $id);
        if ($cached !== null && $cached->id === $id && $cached->kind === 30040) {
            return $cached;
        }
        $local = $this->events->findByIds([$id])[$id] ?? null;
        if ($local instanceof Event && $local->getKind() === 30040) {
            $source = $this->wireEvent($local);
            $this->rememberEvent($source);
            return $source;
        }
        foreach ([$this->booksApi, $this->api] as $client) {
            try {
                $event = $client->getEvent($id);
                if ($event !== null && $event->id === $id && $event->kind === 30040) {
                    $source = (object) $event->toArray();
                    $this->rememberEvent($source);
                    return $source;
                }
            } catch (MercuryApiException $exception) {
                $this->logger->warning('Could not read the bookshelf index from an API.', ['exception' => $exception]);
            }
        }

        try {
            foreach ($this->nostr->getLocalEvents([['kinds' => [30040], 'ids' => [$id], 'limit' => 1]]) as $event) {
                if (($event->id ?? null) === $id && ($event->kind ?? null) === 30040) {
                    $this->rememberEvent($event);

                    return $event;
                }
            }
        } catch (\Throwable $exception) {
            $this->logger->warning('Could not read the bookshelf index from the local relay.', ['exception' => $exception]);
        }

        return null;
    }

    public function hasNestedIndexes(object $source): bool
    {
        foreach ($this->references($source) as $reference) {
            if ($reference['kind'] === 30040) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve an index by following occurrence positions from the exact root.
     * Every ancestor and target is reauthorized on each request.
     *
     * @param list<int> $path
     * @param list<string> $boundRevisions exact IDs for already opened ancestors
     * @param list<string> $resolvedRevisions
     */
    public function indexAtPath(object $root, array $path, array $boundRevisions = [], ?array &$resolvedRevisions = null): ?object
    {
        $resolvedRevisions = [];
        $revisions = $pendingVisibility = [];
        $source = $this->followIndexPath($root, $path, $boundRevisions, $revisions, $pendingVisibility);
        if ($source === null || $this->blockedCoordinates($pendingVisibility) !== []) {
            return null;
        }

        $resolvedRevisions = $revisions;
        return $source;
    }

    /**
     * @param list<int> $path
     * @param list<string> $boundRevisions
     * @param list<string> $resolvedRevisions
     * @param array<string, string> $pendingVisibility Coordinates and authors awaiting one visibility check
     */
    private function followIndexPath(
        object $root,
        array $path,
        array $boundRevisions,
        array &$resolvedRevisions,
        array &$pendingVisibility,
    ): ?object {
        $rootCoordinate = $this->publicCoordinate($root);
        if ($rootCoordinate === null || count($path) >= self::MAX_DEPTH || count($boundRevisions) > count($path)) {
            return null;
        }
        $source = $root;
        $ancestors = [$rootCoordinate];
        $pendingVisibility[$rootCoordinate] = strtolower($root->pubkey);
        foreach ($path as $index => $position) {
            $reference = array_slice($this->references($source), 0, self::MAX_ENTRIES, true)[$position] ?? null;
            if ($reference === null || $reference['kind'] !== 30040 || $reference['invalid']
                || in_array($reference['coordinate'], $ancestors, true)) {
                return null;
            }
            if (isset($boundRevisions[$index])) {
                $bound = $boundRevisions[$index];
                if (preg_match('/^[0-9a-f]{64}$/D', $bound) !== 1
                    || ($reference['pin'] !== null && $reference['pin'] !== $bound)) {
                    return null;
                }
                $reference['pin'] = $bound;
                $reference['key'] = $reference['coordinate'] . "\0" . $bound;
            }
            $pendingVisibility[$reference['coordinate']] = $reference['pubkey'];
            $source = $reference['pin'] === null ? null : $this->cachedTarget($reference);
            if ($source === null) {
                // Check the traversed prefix before any uncached descendant lookup.
                if ($this->blockedCoordinates($pendingVisibility) !== []) {
                    return null;
                }
                $pendingVisibility = [];
                $source = $this->targets([$reference])[$reference['key']] ?? null;
            }
            if ($source === null || $this->publicCoordinate($source) === null) {
                return null;
            }
            $resolvedRevisions[] = strtolower($source->id);
            $ancestors[] = $reference['coordinate'];
        }
        return $source;
    }

    /** @return array{title: ?string, summary: ?string} */
    public function indexMetadata(object $source): array
    {
        $tags = is_array($source->tags ?? null) ? $source->tags : [];
        return ['title' => $this->tag($tags, 'title'), 'summary' => $this->tag($tags, 'summary')];
    }

    /** @return array{entries: list<array<string, mixed>>, hasNext: bool, truncated: bool} */
    public function outline(object $source): array
    {
        $key = 'outline:' . $source->id;
        $cached = $this->cacheRead($key);
        if (is_array($cached)) {
            return $cached;
        }
        $references = $this->references($source);
        $entries = [];
        foreach (array_slice($references, 0, self::MAX_ENTRIES) as $reference) {
            $entries[] = [
                'position' => $reference['position'],
                'kind' => $reference['kind'],
                'title' => $reference['identifier'],
                'invalid' => $reference['invalid'],
            ];
        }

        $outline = ['entries' => $entries, 'hasNext' => false, 'truncated' => count($references) > self::MAX_ENTRIES];
        $this->cacheWrite($key, $outline, self::EXACT_CACHE_TTL);

        return $outline;
    }

    /** @param callable(): string $render */
    public function renderedBody(string $eventId, string $content, callable $render): string
    {
        $key = 'html:' . $eventId . ':' . hash('sha256', $content);
        $cached = $this->cacheRead($key);
        if (is_string($cached)) {
            return $cached;
        }
        $html = $render();
        $this->cacheWrite($key, $html, self::EXACT_CACHE_TTL);

        return $html;
    }

    /** @return array<string, mixed>|null */
    public function chapter(object $source, int $position): ?array
    {
        return $this->resolveChapter($source, $position, []);
    }

    /**
     * @param list<int> $path
     * @param list<string> $boundRevisions
     * @return array<string, mixed>|null
     */
    public function chapterAtPath(object $root, array $path, array $boundRevisions, int $position): ?array
    {
        $resolvedRevisions = $pendingVisibility = [];
        $source = $this->followIndexPath($root, $path, $boundRevisions, $resolvedRevisions, $pendingVisibility);
        if ($source === null) {
            return null;
        }
        if ($position === -1) {
            return $this->blockedCoordinates($pendingVisibility) === []
                ? ['id' => $source->id, 'available' => true, 'content' => (string) ($source->content ?? '')]
                : null;
        }

        return $this->resolveChapter($source, $position, $pendingVisibility);
    }

    /**
     * @param array<string, string> $pendingVisibility
     * @return array<string, mixed>|null
     */
    private function resolveChapter(object $source, int $position, array $pendingVisibility): ?array
    {
        $reference = array_slice($this->references($source), 0, self::MAX_ENTRIES, true)[$position] ?? null;
        if ($reference === null || $reference['kind'] !== 30041 || $reference['invalid']) {
            return null;
        }

        $pendingVisibility[$reference['coordinate']] = $reference['pubkey'];
        if ($this->blockedCoordinates($pendingVisibility) !== []) {
            return null;
        }

        $target = $this->targets([$reference])[$reference['key']] ?? null;
        if ($target === null || $this->publicCoordinate($target) === null) {
            return null;
        }

        return [
            'id' => $target->id,
            'available' => true,
            'title' => $this->tag($target->tags ?? [], 'title') ?? $reference['identifier'],
            'summary' => $this->tag($target->tags ?? [], 'summary'),
            'content' => (string) ($target->content ?? ''),
        ];
    }

    /**
     * Return one bounded page of direct children. Nested index targets are
     * deliberately not fetched until their disclosure is opened.
     *
     * @return array{entries: list<array<string, mixed>>, page: int, hasNext: bool, truncated: bool}
     */
    public function childrenPage(object $source, int $page): array
    {
        $references = $this->references($source);
        $selected = array_slice($references, ($page - 1) * self::PAGE_SIZE, self::PAGE_SIZE, true);
        $sections = array_values(array_filter($selected, static fn (array $ref): bool => $ref['kind'] === 30041));
        $targets = $this->readableTargets($this->targets($sections));
        $entries = [];
        foreach ($selected as $ref) {
            $event = $ref['kind'] === 30041 ? ($targets[$ref['key']] ?? null) : null;
            $tags = is_object($event) && is_array($event->tags ?? null) ? $event->tags : [];
            $entries[] = [
                'position' => $ref['position'], 'kind' => $ref['kind'],
                'title' => $event === null ? $ref['identifier'] : ($this->tag($tags, 'title') ?? $ref['identifier']),
                'summary' => $event === null ? null : $this->tag($tags, 'summary'),
                'available' => $event !== null, 'invalid' => $ref['invalid'],
                'content' => $event === null ? null : (string) ($event->content ?? ''),
                'id' => $event->id ?? null,
            ];
        }
        return ['entries' => $entries, 'page' => $page, 'hasNext' => $page < self::MAX_PAGES && count($references) > $page * self::PAGE_SIZE,
            'truncated' => $page === self::MAX_PAGES && count($references) > self::MAX_ENTRIES];
    }

    /**
     * @param array<string, mixed> $book
     * @return array<string, mixed>
     */
    public function expand(array $book, object $source): array
    {
        $rootCoordinate = $this->coordinate($source);
        if ($rootCoordinate === null || !$this->hasNestedIndexes($source)) {
            return $book;
        }
        if ($this->readableTargets(['root' => $source]) === []) {
            throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
        }

        // The nodes form a tree of occurrences, not a set of unique coordinates.
        $nodes = [];
        $frontier = [['event' => $source, 'parent' => null, 'path' => [$rootCoordinate]]];
        $truncated = false;
        for ($depth = 1; $frontier !== [] && $depth <= self::MAX_DEPTH; ++$depth) {
            $pending = [];
            foreach ($frontier as $parent) {
                foreach ($this->references($parent['event']) as $reference) {
                    if (count($nodes) + count($pending) >= self::MAX_ENTRIES) {
                        $truncated = true;
                        break 2;
                    }
                    $pending[] = ['reference' => $reference, 'parent' => $parent['parent'], 'path' => $parent['path']];
                }
            }
            $targets = $this->readableTargets($this->targets(array_column($pending, 'reference')));
            $frontier = [];
            foreach ($pending as $item) {
                $ref = $item['reference'];
                $event = $targets[$ref['key']] ?? null;
                $cycle = $ref['kind'] === 30040 && in_array($ref['coordinate'], $item['path'], true);
                $available = !$cycle && $event !== null;
                $nodeId = count($nodes);
                $nodes[] = [
                    'parent' => $item['parent'],
                    'reference' => $ref,
                    'event' => $available ? $event : null,
                    'depth' => $depth,
                ];
                if ($available && $ref['kind'] === 30040) {
                    if ($depth === self::MAX_DEPTH) {
                        $truncated = $truncated || $this->references($event) !== [];
                    } else {
                        $frontier[] = ['event' => $event, 'parent' => $nodeId, 'path' => [...$item['path'], $ref['coordinate']]];
                    }
                }
            }
        }

        $children = [];
        foreach ($nodes as $id => $node) {
            $children[$node['parent'] ?? -1][] = $id;
        }
        $chapters = [];
        $walk = function (int $parent) use (&$walk, &$chapters, $children, $nodes): void {
            foreach ($children[$parent] ?? [] as $id) {
                $node = $nodes[$id];
                $ref = $node['reference'];
                $event = $node['event'];
                $tags = is_object($event) && is_array($event->tags ?? null) ? $event->tags : [];
                $chapters[] = [
                    'coordinate' => $ref['coordinate'], 'pubkey' => $ref['pubkey'],
                    'identifier' => $ref['identifier'], 'relay' => $ref['relay'], 'eventId' => $ref['pin'],
                    'position' => count($chapters) + 1, 'depth' => $node['depth'], 'kind' => $ref['kind'],
                    'available' => $event !== null,
                    'title' => $event === null ? $ref['identifier'] : ($this->tag($tags, 'title') ?? $ref['identifier']),
                    'summary' => $event === null ? null : $this->tag($tags, 'summary'),
                    'content' => $event === null ? null : (string) ($event->content ?? ''),
                    'id' => $event->id ?? null, 'createdAt' => $event->created_at ?? null,
                ];
                $walk($id);
            }
        };
        $walk(-1);
        $book['chapters'] = $chapters;
        $book['chapterCount'] = count($chapters);
        $book['availableChapterCount'] = count(array_filter($chapters, static fn (array $chapter): bool => $chapter['available']));
        $book['missingChapterCount'] = count($chapters) - $book['availableChapterCount'];
        $book['truncated'] = $truncated;

        return $book;
    }

    /** @return array<int, array{position: int, kind: int, coordinate: string, pubkey: string, identifier: string, relay: ?string, pin: ?string, key: string, invalid: bool}> */
    private function references(object $event): array
    {
        $key = is_string($event->id ?? null) && preg_match('/^[0-9a-f]{64}$/iD', $event->id) === 1
            ? 'references:' . strtolower($event->id) : null;
        $cached = $key === null ? null : $this->cacheRead($key);
        if (is_array($cached)) {
            return $cached;
        }

        $refs = [];
        $position = -1;
        foreach (is_array($event->tags ?? null) ? $event->tags : [] as $tag) {
            ++$position;
            if (!is_array($tag) || ($tag[0] ?? null) !== 'a' || !is_string($tag[1] ?? null)) {
                continue;
            }
            $parts = explode(':', $tag[1], 3);
            if (!in_array($parts[0], ['30040', '30041'], true)) {
                continue;
            }
            $validAddress = count($parts) === 3 && preg_match('/^[0-9a-f]{64}$/iD', $parts[1]) === 1;
            $pubkey = strtolower($parts[1] ?? '');
            $identifier = $parts[2] ?? $tag[1];
            $coordinate = $validAddress ? $parts[0] . ':' . $pubkey . ':' . $identifier : $tag[1];
            $rawPin = array_key_exists(3, $tag) ? $tag[3] : '';
            $invalid = !$validAddress || !is_string($rawPin) || ($rawPin !== '' && preg_match('/^[0-9a-f]{64}$/iD', $rawPin) !== 1);
            $pin = !$invalid && $rawPin !== '' ? strtolower($rawPin) : null;
            $relay = is_string($tag[2] ?? null) && in_array(parse_url($tag[2], PHP_URL_SCHEME), ['ws', 'wss'], true)
                && parse_url($tag[2], PHP_URL_HOST) ? $tag[2] : null;
            $refs[$position] = [
                'position' => $position, 'kind' => (int) $parts[0], 'coordinate' => $coordinate, 'pubkey' => $pubkey,
                'identifier' => $identifier, 'relay' => $relay, 'pin' => $pin, 'invalid' => $invalid,
                'key' => $coordinate . "\0" . ($invalid ? 'invalid' : ($pin ?? 'latest')),
            ];
        }
        if ($key !== null) {
            $this->cacheWrite($key, $refs, self::EXACT_CACHE_TTL);
        }

        return $refs;
    }

    /**
     * @param list<array<string, mixed>> $refs
     * @return array<string, object>
     */
    private function targets(array $refs): array
    {
        $result = $uncached = [];
        $ids = $coordinates = [];
        foreach ($refs as $ref) {
            if ($ref['invalid']) {
                continue;
            }
            if ($ref['pin'] !== null) {
                $cached = $this->cachedTarget($ref);
                if ($cached !== null) {
                    $result[$ref['key']] = $cached;
                    continue;
                }
                $ids[$ref['pin']] = true;
            } else {
                $coordinates[$ref['coordinate']] = true;
            }
            $uncached[] = $ref;
        }
        if ($uncached === []) {
            return $result;
        }

        $local = $this->events->findBookshelfReaderTargets(array_keys($coordinates), array_keys($ids));
        $events = array_map($this->wireEvent(...), array_values($local['coordinates'] + $local['eventIds']));
        $localMatches = $this->matchTargets($uncached, $events);
        foreach ($localMatches as $event) {
            if ($event->kind === 30040 || isset($ids[$event->id])) {
                // Opened indexes and pinned sections can serve later exact-ID reads.
                $this->rememberEvent($event);
            }
        }
        $result += $localMatches;
        foreach ($uncached as $ref) {
            if ($ref['pin'] !== null || isset($result[$ref['key']])) {
                continue;
            }
            $cached = $this->cachedTarget($ref);
            if ($cached !== null) {
                $result[$ref['key']] = $cached;
            }
        }
        $missing = array_values(array_filter($uncached, static fn (array $ref): bool =>
            !isset($result[$ref['key']])
        ));
        if ($missing === []) {
            return $result;
        }
        foreach ([[$this->booksApi, true], [$this->api, false]] as [$client, $orderedCoordinates]) {
            for ($pass = 0; $pass < self::MAX_API_PASSES; ++$pass) {
                $unresolved = array_values(array_filter($missing, static fn (array $ref): bool => !isset($result[$ref['key']])));
                if ($unresolved === []) {
                    break 2;
                }
                // The Books API provides the timestamp and ID ordering. The
                // separate Mercury coordinate lookup can inspect tied results.
                $matched = $this->matchTargets($unresolved, $this->apiTargets(
                    $client, $unresolved, $orderedCoordinates,
                ));
                if ($matched === []) {
                    break;
                }
                $result += $matched;
            }
        }
        if ($this->mercuryCoordinates !== null) {
            $checked = [];
            foreach ($missing as $ref) {
                if ($ref['pin'] !== null || isset($result[$ref['key']]) || isset($checked[$ref['coordinate']])) {
                    continue;
                }
                $checked[$ref['coordinate']] = true;
                $result += $this->matchTargets([$ref], $this->mercuryCoordinates->find(
                    $ref['kind'], $ref['pubkey'], $ref['identifier'],
                ));
            }
        }
        $missingIds = $missingCoordinates = [];
        foreach ($missing as $ref) {
            if (isset($result[$ref['key']])) {
                continue;
            }
            if ($ref['pin'] !== null) {
                $missingIds[$ref['pin']] = true;
            } else {
                $missingCoordinates[$ref['coordinate']] = true;
            }
        }
        $groups = [];
        $filters = [];
        $skippedCoordinates = 0;
        if ($missingIds !== []) {
            $filters[] = ['kinds' => [30040, 30041], 'ids' => array_keys($missingIds), 'limit' => count($missingIds)];
        }
        foreach (array_keys($missingCoordinates) as $coordinate) {
            [$kind, $pubkey, $identifier] = explode(':', $coordinate, 3);
            $group = $kind . ':' . $pubkey;
            if (!isset($groups[$group]) && count($groups) >= self::MAX_RELAY_GROUPS) {
                ++$skippedCoordinates;
                continue;
            }
            $groups[$group][] = $identifier;
        }
        foreach ($groups as $group => $identifiers) {
            [$kind, $pubkey] = explode(':', $group, 2);
            $filters[] = ['kinds' => [(int) $kind], 'authors' => [$pubkey], '#d' => $identifiers, 'limit' => count($identifiers)];
        }
        if ($skippedCoordinates > 0) {
            $this->logger->warning('Bookshelf relay lookup reached its author and kind limit.', [
                'remaining_coordinates' => $skippedCoordinates,
            ]);
        }
        if ($filters !== []) {
            try {
                $result += $this->matchTargets($missing, $this->nostr->getLocalEvents($filters));
            } catch (\Throwable $exception) {
                $this->logger->warning('Could not resolve Bookshelf references from the local relay.', ['exception' => $exception]);
            }
        }
        foreach ($missing as $ref) {
            $event = $result[$ref['key']] ?? null;
            if ($event === null) {
                continue;
            }
            $this->rememberEvent($event);
            if ($ref['pin'] === null) {
                $this->cacheWrite('coordinate:' . $ref['coordinate'], (array) $event, self::COORDINATE_CACHE_TTL);
            }
        }
        return $result;
    }

    /** @param array<string, mixed> $reference */
    private function cachedTarget(array $reference): ?object
    {
        $key = $reference['pin'] === null ? 'coordinate:' . $reference['coordinate'] : 'event:' . $reference['pin'];
        $cached = $this->cachedEvent($key);

        return $cached === null ? null : ($this->matchTargets([$reference], [$cached])[$reference['key']] ?? null);
    }

    private function cachedEvent(string $key): ?object
    {
        $cached = $this->cacheRead($key);
        return is_array($cached) && isset($cached['id'], $cached['kind'], $cached['pubkey'], $cached['tags'], $cached['created_at'])
            ? (object) $cached : null;
    }

    private function rememberEvent(object $event): void
    {
        if (is_string($event->id ?? null) && preg_match('/^[0-9a-f]{64}$/D', $event->id) === 1
            && $this->coordinate($event) !== null) {
            $this->cacheWrite('event:' . $event->id, (array) $event, self::EXACT_CACHE_TTL);
        }
    }

    private function cacheRead(string $key): mixed
    {
        try {
            $item = $this->cache->getItem('bookshelf_reader_v1_' . hash('sha256', $key));
            return $item->isHit() ? $item->get() : null;
        } catch (CacheException $exception) {
            $this->logger->warning('Bookshelf reader cache read failed.', ['exception' => $exception]);
            return null;
        }
    }

    private function cacheWrite(string $key, mixed $value, int $ttl): void
    {
        try {
            $item = $this->cache->getItem('bookshelf_reader_v1_' . hash('sha256', $key));
            $item->set($value)->expiresAfter($ttl);
            if (!$this->cache->save($item)) {
                $this->logger->warning('Bookshelf reader cache write failed.');
            }
        } catch (CacheException $exception) {
            $this->logger->warning('Bookshelf reader cache write failed.', ['exception' => $exception]);
        }
    }

    /**
     * @param list<array<string, mixed>> $refs
     * @return list<object>
     */
    private function apiTargets(MercuryApiClientInterface $client, array $refs, bool $orderedCoordinates): array
    {
        $events = [];
        foreach ([30040, 30041] as $kind) {
            $matching = array_values(array_filter($refs, static fn (array $ref): bool => $ref['kind'] === $kind));
            $ids = array_values(array_unique(array_filter(array_column($matching, 'pin'), 'is_string')));
            if ($ids !== []) {
                $events = array_merge($events, $this->apiEvents(fn (): array => $kind === 30040
                    ? $client->getPublicationEventsByIds($ids) : $client->getEventsByIds($ids)));
            }
            $unpinned = $orderedCoordinates
                ? array_values(array_filter($matching, static fn (array $ref): bool => $ref['pin'] === null))
                : [];
            $seen = [];
            foreach ($unpinned as $ref) {
                // Mercury strips blank identifiers and otherwise turns this
                // into a broad author-only query. Let exact relay lookup handle it.
                if (trim($ref['identifier']) === '' || isset($seen[$ref['coordinate']])) {
                    continue;
                }
                $seen[$ref['coordinate']] = true;
                $events = array_merge($events, $this->apiEvents(fn (): array => $kind === 30040
                    ? $client->getPublicationsByAuthors([$ref['pubkey']], 1, [$ref['identifier']])
                    : $client->getChaptersByAuthors([$ref['pubkey']], 1, [$ref['identifier']])));
            }
        }
        return $events;
    }

    /**
     * @param callable(): list<MercuryEvent> $fetch
     * @return list<object>
     */
    private function apiEvents(callable $fetch): array
    {
        try {
            return array_map(static fn (MercuryEvent $event): object => (object) $event->toArray(), $fetch());
        } catch (MercuryApiException $exception) {
            $this->logger->warning('Could not resolve bookshelf contents from an API.', ['exception' => $exception]);
            return [];
        }
    }

    /**
     * @param list<array<string, mixed>> $refs
     * @param list<object> $events
     * @return array<string, object>
     */
    private function matchTargets(array $refs, array $events): array
    {
        $result = [];
        foreach ($events as $event) {
            $coordinate = $this->coordinate($event);
            if ($coordinate === null || !is_string($event->id ?? null)) {
                continue;
            }
            foreach ($refs as $ref) {
                if ($ref['invalid'] || $ref['coordinate'] !== $coordinate
                    || ($ref['pin'] !== null && $ref['pin'] !== strtolower($event->id))) {
                    continue;
                }
                $current = $result[$ref['key']] ?? null;
                if ($current === null || ($event->created_at ?? 0) > ($current->created_at ?? 0)
                    || (($event->created_at ?? 0) === ($current->created_at ?? 0) && strcmp($event->id, $current->id) < 0)) {
                    $result[$ref['key']] = $event;
                }
            }
        }
        return $result;
    }

    private function coordinate(object $event): ?string
    {
        $kind = $event->kind ?? null;
        $pubkey = $event->pubkey ?? null;
        $identifier = $this->tag(is_array($event->tags ?? null) ? $event->tags : [], 'd');
        if (!in_array($kind, [30040, 30041], true) || !is_string($pubkey)
            || preg_match('/^[0-9a-f]{64}$/iD', $pubkey) !== 1 || $identifier === null) {
            return null;
        }
        return $kind . ':' . strtolower($pubkey) . ':' . $identifier;
    }

    private function wireEvent(Event $event): object
    {
        return (object) [
            'id' => $event->getId(), 'kind' => $event->getKind(), 'pubkey' => $event->getPubkey(),
            'created_at' => $event->getCreatedAt(), 'tags' => $event->getTags(), 'content' => $event->getContent(),
        ];
    }

    /**
     * @param array<string, object> $targets
     * @return array<string, object>
     */
    private function readableTargets(array $targets): array
    {
        $candidates = $pubkeysByCoordinate = [];
        foreach ($targets as $key => $event) {
            $coordinate = $this->publicCoordinate($event);
            if ($coordinate === null) {
                continue;
            }
            $candidates[$key] = [$event, $coordinate];
            $pubkeysByCoordinate[$coordinate] = strtolower($event->pubkey);
        }
        $blocked = $this->blockedCoordinates($pubkeysByCoordinate);
        $readable = [];
        foreach ($candidates as $key => [$event, $coordinate]) {
            if (!isset($blocked[$coordinate])) {
                $readable[$key] = $event;
            }
        }
        return $readable;
    }

    /**
     * @param array<string, string> $pubkeysByCoordinate
     * @return array<string, true>
     */
    private function blockedCoordinates(array $pubkeysByCoordinate): array
    {
        if ($pubkeysByCoordinate === []) {
            return [];
        }

        $pubkeys = array_values(array_unique($pubkeysByCoordinate));
        $suppressed = array_fill_keys($this->authors->suppressedPubkeys($pubkeys), true);
        $blocked = array_fill_keys($this->hidden->findHiddenCoordinates(array_keys($pubkeysByCoordinate)), true);
        foreach ($pubkeysByCoordinate as $coordinate => $pubkey) {
            if (isset($suppressed[$pubkey])) {
                $blocked[$coordinate] = true;
            }
        }

        return $blocked;
    }

    private function publicCoordinate(object $event): ?string
    {
        $coordinate = $this->coordinate($event);
        $tags = is_array($event->tags ?? null) ? $event->tags : [];
        if ($coordinate === null || !is_string($event->id ?? null)
            || preg_match('/^[0-9a-f]{64}$/iD', $event->id) !== 1
            || !is_int($event->created_at ?? null)
            || ($event->kind === 30040
                ? EventWorkspaceScopePolicy::isScopedInventoryList($event->kind, $tags)
                : EventWorkspaceScopePolicy::isScopedTarget($event->kind, $tags))) {
            return null;
        }

        $entity = new Event();
        $entity->setTags(array_values(array_filter($tags, static fn ($tag): bool =>
            is_array($tag) && is_string($tag[0] ?? null)
            && (!in_array($tag[0], ['t', 'L'], true) || !isset($tag[1]) || is_string($tag[1]))
        )));

        return $entity->isNSFW() ? null : $coordinate;
    }

    /** @param array<array-key, mixed> $tags */
    private function tag(array $tags, string $name): ?string
    {
        foreach ($tags as $tag) {
            if (is_array($tag) && ($tag[0] ?? null) === $name && is_string($tag[1] ?? null)) {
                return $tag[1];
            }
        }
        return null;
    }
}
