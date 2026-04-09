<?php

declare(strict_types=1);

namespace App\Twig\Components\Organisms;

use App\Dto\UserMetadata;
use App\Entity\Event;
use App\Enum\KindsEnum;
use App\Repository\ArticleRepository;
use App\Service\Cache\RedisCacheService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use swentel\nostr\Key\Key;

/**
 * Lists all known follow packs (kind 39089) whose members have
 * more than 5 articles in our index.
 */
#[AsTwigComponent]
final class FollowPackList
{
    private const MIN_ARTICLE_COUNT = 5;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ArticleRepository $articleRepository,
        private readonly RedisCacheService $redisCacheService,
    ) {}

    /**
     * @return array<int, array{
     *     title: string,
     *     description: ?string,
     *     image: ?string,
     *     memberCount: int,
     *     articleCount: int,
     *     pubkey: string,
     *     npub: string,
     *     dtag: string,
     *     authorName: string,
     *     authorPicture: string,
     * }>
     */
    public function getPacks(): array
    {
        // 1. Fetch all follow pack events
        $events = $this->em->getRepository(Event::class)->findBy(
            ['kind' => KindsEnum::FOLLOW_PACK->value],
            ['created_at' => 'DESC']
        );

        // 2. Deduplicate by pubkey:d-tag (keep latest)
        $deduplicated = [];
        foreach ($events as $event) {
            $slug = $event->getSlug();
            $key = $event->getPubkey() . ':' . $slug;
            if (!isset($deduplicated[$key]) || $event->getCreatedAt() > $deduplicated[$key]->getCreatedAt()) {
                $deduplicated[$key] = $event;
            }
        }

        // 3. Parse each pack and collect all member pubkeys
        $allMemberPubkeys = [];
        $parsedPacks = [];

        foreach ($deduplicated as $event) {
            $title = '';
            $description = null;
            $image = null;
            $dtag = '';
            $memberPubkeys = [];

            foreach ($event->getTags() as $tag) {
                if (!is_array($tag) || empty($tag)) {
                    continue;
                }
                $k = $tag[0] ?? '';
                match ($k) {
                    'title' => $title = $tag[1] ?? '',
                    'description' => $description = $tag[1] ?? null,
                    'about' => $description ??= $tag[1] ?? null,
                    'image' => $image = $tag[1] ?? null,
                    'picture' => $image ??= $tag[1] ?? null,
                    'd' => $dtag = $tag[1] ?? '',
                    'p' => $memberPubkeys[] = $tag[1] ?? '',
                    default => null,
                };
            }

            $memberPubkeys = array_filter(array_unique($memberPubkeys));

            if (empty($dtag) || empty($memberPubkeys)) {
                continue;
            }

            foreach ($memberPubkeys as $pk) {
                $allMemberPubkeys[$pk] = true;
            }

            $parsedPacks[] = [
                'event' => $event,
                'title' => $title ?: '(untitled)',
                'description' => $description,
                'image' => $image,
                'dtag' => $dtag,
                'memberPubkeys' => $memberPubkeys,
            ];
        }

        // 4. Batch-count articles per pubkey
        $articleCounts = $this->articleRepository->countArticlesByPubkeys(
            array_keys($allMemberPubkeys)
        );

        // 5. Filter packs by total article count > threshold
        $qualifiedPacks = [];
        foreach ($parsedPacks as $pack) {
            $totalArticles = 0;
            foreach ($pack['memberPubkeys'] as $pk) {
                $totalArticles += $articleCounts[$pk] ?? 0;
            }
            if ($totalArticles > self::MIN_ARTICLE_COUNT) {
                $pack['articleCount'] = $totalArticles;
                $qualifiedPacks[] = $pack;
            }
        }

        // 6. Sort by article count descending
        usort($qualifiedPacks, fn($a, $b) => $b['articleCount'] <=> $a['articleCount']);

        // 7. Resolve author metadata
        $authorPubkeys = array_unique(array_map(
            fn($p) => $p['event']->getPubkey(),
            $qualifiedPacks
        ));
        $metadataMap = !empty($authorPubkeys)
            ? $this->redisCacheService->getMultipleMetadata($authorPubkeys)
            : [];

        $keyHelper = new Key();

        // 8. Build the final output
        $result = [];
        foreach ($qualifiedPacks as $pack) {
            $pubkey = $pack['event']->getPubkey();
            $meta = $metadataMap[$pubkey] ?? null;
            $std = $meta instanceof UserMetadata ? $meta->toStdClass() : $meta;

            try {
                $npub = $keyHelper->convertPublicKeyToBech32($pubkey);
            } catch (\Throwable) {
                $npub = $pubkey;
            }

            $result[] = [
                'title' => $pack['title'],
                'description' => $pack['description'],
                'image' => $pack['image'],
                'memberCount' => count($pack['memberPubkeys']),
                'articleCount' => $pack['articleCount'],
                'pubkey' => $pubkey,
                'npub' => $npub,
                'dtag' => $pack['dtag'],
                'authorName' => $std->display_name ?? $std->name ?? '',
                'authorPicture' => $std->picture ?? '',
            ];
        }

        return $result;
    }
}

