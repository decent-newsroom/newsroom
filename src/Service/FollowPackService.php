<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\UserMetadata;
use App\Entity\Article;
use App\Entity\Event;
use App\Enum\FollowPackPurpose;
use App\Enum\KindsEnum;
use App\Repository\FollowPackSourceRepository;
use App\Service\Cache\RedisCacheService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Resolves follow pack sources to lists of pubkeys and fetches their articles.
 */
class FollowPackService
{
    public function __construct(
        private readonly FollowPackSourceRepository $sourceRepository,
        private readonly EntityManagerInterface $em,
        private readonly RedisCacheService $redisCacheService,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Get the list of hex pubkeys from the follow pack configured for the given purpose.
     *
     * @return string[] Hex pubkeys
     */
    public function getPubkeysForPurpose(FollowPackPurpose $purpose): array
    {
        $source = $this->sourceRepository->findByPurpose($purpose);
        if (!$source) {
            return [];
        }

        return $this->getPubkeysFromCoordinate($source->getCoordinate());
    }

    /**
     * Parse a coordinate (e.g. "39089:pubkey:d-tag") and find the matching Event,
     * then extract all 'p' tags as hex pubkeys.
     *
     * @return string[]
     */
    public function getPubkeysFromCoordinate(string $coordinate): array
    {
        $parts = explode(':', $coordinate, 3);
        if (count($parts) < 3) {
            $this->logger->warning('Invalid follow pack coordinate', ['coordinate' => $coordinate]);
            return [];
        }

        [$kindStr, $pubkey, $dTag] = $parts;
        $kind = (int) $kindStr;

        // Find the event in the database matching kind, pubkey, and d-tag
        $events = $this->em->getRepository(Event::class)->findBy([
            'kind' => $kind,
            'pubkey' => $pubkey,
        ]);

        // Filter by d-tag
        $matchingEvent = null;
        foreach ($events as $event) {
            if ($event->getSlug() === $dTag) {
                // Keep the latest one if there are multiple
                if (!$matchingEvent || $event->getCreatedAt() > $matchingEvent->getCreatedAt()) {
                    $matchingEvent = $event;
                }
            }
        }

        if (!$matchingEvent) {
            $this->logger->info('Follow pack event not found in DB', ['coordinate' => $coordinate]);
            return [];
        }

        // Extract 'p' tags
        $pubkeys = [];
        foreach ($matchingEvent->getTags() as $tag) {
            if (is_array($tag) && count($tag) >= 2 && $tag[0] === 'p') {
                $pubkeys[] = $tag[1];
            }
        }

        return array_values(array_unique($pubkeys));
    }

    /**
     * Fetch articles from pubkeys of a follow pack, with metadata.
     *
     * @return array{articles: Article[], authorsMetadata: array<string, \stdClass>}
     */
    public function getArticlesForPurpose(FollowPackPurpose $purpose, int $limit = 50): array
    {
        $pubkeys = $this->getPubkeysForPurpose($purpose);

        if (empty($pubkeys)) {
            return ['articles' => [], 'authorsMetadata' => []];
        }

        return $this->getArticlesForPubkeys($pubkeys, $limit);
    }

    /**
     * @return array{articles: Article[], authorsMetadata: array<string, \stdClass>}
     */
    public function getArticlesForPubkeys(array $pubkeys, int $limit = 50): array
    {
        $articleRepo = $this->em->getRepository(Article::class);

        $qb = $articleRepo->createQueryBuilder('a');
        $qb->where($qb->expr()->in('a.pubkey', ':pubkeys'))
            ->setParameter('pubkeys', $pubkeys)
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults($limit);

        $articles = $qb->getQuery()->getResult();

        // Deduplicate by slug+pubkey — newest revision wins
        $seen = [];
        $articles = array_values(array_filter($articles, function (Article $a) use (&$seen) {
            $key = $a->getPubkey() . ':' . $a->getSlug();
            if (isset($seen[$key])) {
                return false;
            }
            $seen[$key] = true;
            return true;
        }));

        // Resolve author metadata
        $authorsMetadata = [];
        $authorPubkeys = array_unique(array_map(fn(Article $a) => $a->getPubkey(), $articles));

        if (!empty($authorPubkeys)) {
            $metadataMap = $this->redisCacheService->getMultipleMetadata($authorPubkeys);
            foreach ($metadataMap as $pubkey => $metadata) {
                $authorsMetadata[$pubkey] = $metadata instanceof UserMetadata
                    ? $metadata->toStdClass()
                    : $metadata;
            }
        }

        return ['articles' => $articles, 'authorsMetadata' => $authorsMetadata];
    }

    /**
     * Get all kind 39089 events from the database for a given pubkey.
     *
     * @return Event[]
     */
    public function getFollowPackEvents(string $pubkey): array
    {
        return $this->em->getRepository(Event::class)->findBy(
            ['kind' => KindsEnum::FOLLOW_PACK->value, 'pubkey' => $pubkey],
            ['created_at' => 'DESC']
        );
    }

    /**
     * Get all kind 39089 events from the database (any pubkey).
     *
     * @return Event[]
     */
    public function getAllFollowPackEvents(): array
    {
        return $this->em->getRepository(Event::class)->findBy(
            ['kind' => KindsEnum::FOLLOW_PACK->value],
            ['created_at' => 'DESC']
        );
    }
}

