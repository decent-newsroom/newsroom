<?php

declare(strict_types=1);

namespace App\Service\Nostr;

use App\Entity\Article;
use App\Enum\KindsEnum;
use App\Factory\ArticleFactory;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use swentel\nostr\Filter\Filter;
use swentel\nostr\Message\RequestMessage;
use swentel\nostr\Relay\RelaySet;
use swentel\nostr\Request\Request;
use swentel\nostr\Subscription\Subscription;

/**
 * All article / long-form content operations, extracted from NostrClient.
 *
 * Owns:
 *   - Fetching long-form events by pubkey, slug, naddr, or coordinate
 *   - Persisting Article entities to the database
 *   - Ingesting a time-range of long-form events
 */
class ArticleFetchService
{
    public function __construct(
        private readonly NostrRequestExecutor  $executor,
        private readonly RelaySetFactory       $relaySetFactory,
        private readonly RelayRegistry         $relayRegistry,
        private readonly ArticleFactory        $articleFactory,
        private readonly EntityManagerInterface $entityManager,
        private readonly ManagerRegistry       $managerRegistry,
        private readonly LoggerInterface       $logger,
        private readonly ?string               $nostrDefaultRelay = null,
    ) {}

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Fetch long-form (or any kind) articles for a pubkey and save them to the DB.
     *
     * @return Article[]
     */
    public function fetchForPubkey(string $pubkey, ?int $since = null, int $kind = KindsEnum::LONGFORM->value): array
    {
        $relaySet = $this->relaySetFactory->forAuthor($pubkey);

        $this->logger->info('Fetching articles for pubkey', [
            'pubkey'    => $pubkey,
            'kind'      => $kind,
            'relay_set' => $this->nostrDefaultRelay ? 'local' : 'author',
        ]);

        $filters = ['authors' => [$pubkey], 'limit' => 20];
        if ($since !== null && $since > 0) {
            $filters['since'] = $since;
        }

        return $this->executor->fetch(
            kinds: [$kind],
            filters: $filters,
            relaySet: $relaySet,
            handler: function ($event) {
                $article = $this->articleFactory->createFromLongFormContentEvent($event);
                $this->save($article);
                return $article;
            }
        );
    }

    /**
     * Fetch a single long-form event by naddr components and save to DB.
     *
     * Tries author relays first, falls back to content relays.
     *
     * @throws \Exception on relay error
     */
    public function fetchFromNaddr(string $slug, array $relayList, string $author, int $kind): bool
    {
        $this->logger->info('Fetching long-form from naddr', [
            'slug'       => $slug,
            'author'     => $author,
            'kind'       => $kind,
            'relay_list' => $relayList,
        ]);

        $primaryRelaySet  = $this->relaySetFactory->forAuthorWithFallback($author, $relayList);
        $contentRelays    = $this->relayRegistry->getContentRelays();
        $fallbackRelaySet = empty($relayList)
            ? $this->relaySetFactory->fromUrls($contentRelays)
            : $this->relaySetFactory->fromUrls(array_unique(array_merge($relayList, $contentRelays)));

        $filters = [
            'authors' => [$author],
            'tag'     => ['#d', [$slug]],
        ];

        try {
            $event = $this->executor->fetchFirst([$kind], $filters, $primaryRelaySet, $fallbackRelaySet);

            if ($event !== null) {
                $this->saveFromWrapper($event);
                return true;
            }

            $this->logger->warning('No events found even after fallback relay search', [
                'slug'   => $slug,
                'author' => $author,
            ]);
            return false;

        } catch (\Exception $e) {
            $this->logger->error('Error querying relays', ['error' => $e->getMessage()]);
            throw new \Exception('Error querying relays', 0, $e);
        }
    }

    /**
     * Fetch the latest long-form articles from the local/default relay and save to DB.
     *
     * @return Article[]
     */
    public function fetchLatest(int $limit = 50, ?int $since = null): array
    {
        $relaySet = $this->nostrDefaultRelay
            ? $this->relaySetFactory->fromUrls([$this->nostrDefaultRelay])
            : $this->relaySetFactory->getDefault();

        $this->logger->info('Fetching latest long-form articles', [
            'relay'  => $this->nostrDefaultRelay ?? 'default',
            'limit'  => $limit,
            'since'  => $since,
        ]);

        $filters = ['limit' => $limit];
        if ($since !== null && $since > 0) {
            $filters['since'] = $since;
        }

        try {
            $events = $this->executor->fetch(
                kinds: [KindsEnum::LONGFORM->value],
                filters: $filters,
                relaySet: $relaySet,
                handler: function ($event) {
                    try {
                        $article = $this->articleFactory->createFromLongFormContentEvent($event);
                        $this->save($article);
                        return $article;
                    } catch (\Throwable $e) {
                        $this->logger->error('Failed converting event to Article', [
                            'error'    => $e->getMessage(),
                            'event_id' => $event->id ?? null,
                        ]);
                        return null;
                    }
                }
            );
        } catch (\Throwable $e) {
            $this->logger->error('Error fetching latest long-form articles', ['error' => $e->getMessage()]);
            return [];
        }

        $articles = array_filter($events, fn($a) => $a instanceof Article);

        // Deduplicate by slug, keeping newest
        $bySlug = [];
        foreach ($articles as $article) {
            $slug = $article->getSlug();
            if ($slug === '') {
                continue;
            }
            if (!isset($bySlug[$slug]) || $article->getCreatedAt() > $bySlug[$slug]->getCreatedAt()) {
                $bySlug[$slug] = $article;
            }
        }

        $deduped = array_values($bySlug);
        usort($deduped, fn($a, $b) => $b->getCreatedAt() <=> $a->getCreatedAt());
        return $deduped;
    }

    /**
     * Fetch articles by a list of #d tag slugs from the default relay set.
     *
     * @return array<string, object>  event ID => event
     */
    public function fetchBySlugList(array $slugs): array
    {
        if (empty($slugs)) {
            return [];
        }

        $articles = [];
        $subscription = new Subscription();
        $subscriptionId = $subscription->setId();
        $filter = new Filter();
        $filter->setKinds([KindsEnum::LONGFORM]);
        $filter->setTag('#d', $slugs);
        $requestMessage = new RequestMessage($subscriptionId, [$filter]);

        try {
            $request  = new Request($this->relaySetFactory->getDefault(), $requestMessage);
            $response = $request->send();

            foreach ($response as $value) {
                foreach ($value as $item) {
                    if ($item->type === 'EVENT' && !isset($articles[$item->event->id])) {
                        $articles[$item->event->id] = $item->event;
                    }
                }
            }
        } catch (\Exception $e) {
            $this->logger->error('Error querying relays for slug list', ['error' => $e->getMessage()]);
        }

        return $articles;
    }

    /**
     * Fetch articles by an array of coordinate strings ("kind:pubkey:identifier").
     *
     * @return array<string, object>  coordinate => event
     */
    public function fetchByCoordinates(array $coordinates): array
    {
        $articlesMap = [];

        foreach ($coordinates as $coordinate) {
            $parts  = explode(':', $coordinate, 3);
            $kind   = (int) $parts[0];
            $pubkey = $parts[1];
            $slug   = $parts[2];

            $relayList = [];
            try {
                $relayList = $this->relaySetFactory->forAuthor($pubkey)->getRelays()
                    ? array_map(fn($r) => $r->getUrl(), $this->relaySetFactory->forAuthor($pubkey)->getRelays())
                    : [];
            } catch (\Exception $e) {
                $this->logger->warning('Failed to get author relays for coordinate', [
                    'pubkey'     => $pubkey,
                    'coordinate' => $coordinate,
                    'error'      => $e->getMessage(),
                ]);
            }

            if (empty($relayList)) {
                $relayList = $this->relayRegistry->getContentRelays();
            }

            $relaySet       = $this->relaySetFactory->fromUrls($relayList);
            $subscription   = new Subscription();
            $subscriptionId = $subscription->setId();
            $filter         = new Filter();
            $filter->setKinds([$kind]);
            $filter->setAuthors([$pubkey]);
            $filter->setTag('#d', [$slug]);
            $requestMessage = new RequestMessage($subscriptionId, [$filter]);

            try {
                $request = new Request($relaySet, $requestMessage);
                $events  = $this->executor->process(
                    $this->executor->execute($this->executor->buildRequest([$kind], [
                        'authors' => [$pubkey],
                        'tag'     => ['#d', [$slug]],
                    ], $relaySet)),
                    function ($event) use ($coordinate) {
                        $this->logger->info('Received event for coordinate', [
                            'event_id'   => $event->id,
                            'coordinate' => $coordinate,
                        ]);
                        return $event;
                    }
                );

                if (!empty($events)) {
                    $articlesMap[$coordinate] = $events[0];
                    $this->logger->info('Found article in author relays', ['coordinate' => $coordinate]);
                } else {
                    $fallbackRequest = $this->executor->buildRequest([$kind], [
                        'authors' => [$pubkey],
                        'tag'     => ['#d', [$slug]],
                    ], $this->relaySetFactory->getDefault());

                    $fallbackEvents = $this->executor->process(
                        $this->executor->execute($fallbackRequest),
                        fn($event) => $event
                    );

                    if (!empty($fallbackEvents)) {
                        $articlesMap[$coordinate] = $fallbackEvents[0];
                        $this->logger->info('Found article in default relays', ['coordinate' => $coordinate]);
                    } else {
                        $this->logger->warning('Article not found in any relay', ['coordinate' => $coordinate]);
                    }
                }
            } catch (\Exception $e) {
                $this->logger->error('Error fetching article', [
                    'coordinate' => $coordinate,
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        $this->logger->info('Finished fetching articles by coordinates', [
            'total_coordinates' => count($coordinates),
            'articles_found'    => count($articlesMap),
        ]);

        return $articlesMap;
    }

    /**
     * Ingest long-form events between two timestamps, saving each to the DB.
     * (Was getLongFormContent() in NostrClient.)
     */
    public function ingestRange(?int $from = null, ?int $to = null): void
    {
        $subscription   = new Subscription();
        $subscriptionId = $subscription->setId();
        $filter         = new Filter();
        $filter->setKinds([KindsEnum::LONGFORM]);
        $filter->setSince(strtotime('-1 week'));
        if ($from !== null) {
            $filter->setSince($from);
        }
        if ($to !== null) {
            $filter->setUntil($to);
        }
        $requestMessage = new RequestMessage($subscriptionId, [$filter]);
        $relaySet       = $this->relaySetFactory->withLocalRelay($this->relayRegistry->getContentRelays());
        $request        = new Request($relaySet, $requestMessage);

        $events = $this->executor->process(
            $this->executor->execute($this->executor->buildRequest(
                [KindsEnum::LONGFORM],
                $from !== null ? ['since' => $from] : [],
                $relaySet
            )),
            fn($event) => $event
        );

        foreach ($events as $event) {
            $article = $this->articleFactory->createFromLongFormContentEvent($event);
            $this->save($article);
        }
    }

    /**
     * Persist an Article to the database if it does not already exist.
     */
    public function save(Article $article): void
    {
        $saved = $this->entityManager->getRepository(Article::class)
            ->findOneBy(['eventId' => $article->getEventId()]);

        if (!$saved) {
            try {
                $this->logger->info('Saving article', ['article' => $article]);
                $this->entityManager->persist($article);
                $this->entityManager->flush();
            } catch (\Exception $e) {
                $this->logger->error($e->getMessage());
                $this->managerRegistry->resetManager();
            }
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Wrap a raw event object in a minimal envelope and save it.
     */
    private function saveFromWrapper(object $event): void
    {
        $article = $this->articleFactory->createFromLongFormContentEvent($event);
        $this->save($article);
    }
}

