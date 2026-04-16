<?php

declare(strict_types=1);

namespace App\Twig\Components\Organisms;

use App\Dto\UserMetadata;
use App\Entity\Event;
use App\Enum\KindsEnum;
use App\Service\Cache\RedisCacheService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use swentel\nostr\Key\Key;

/**
 * Lists all known feed expressions (kind 30880) from the database,
 * deduplicated by pubkey:d-tag (latest wins), with author metadata.
 */
#[AsTwigComponent]
final class ExpressionList
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RedisCacheService $redisCacheService,
    ) {}

    /**
     * @return array<int, array{
     *     title: string,
     *     description: ?string,
     *     content: string,
     *     stageCount: int,
     *     pubkey: string,
     *     npub: string,
     *     dtag: string,
     *     authorName: string,
     *     authorPicture: string,
     *     createdAt: int,
     * }>
     */
    public function getExpressions(): array
    {
        // 1. Fetch all feed expression events
        $events = $this->em->getRepository(Event::class)->findBy(
            ['kind' => KindsEnum::FEED_EXPRESSION->value],
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

        // 3. Parse each expression, extract metadata from tags
        $parsedExpressions = [];
        foreach ($deduplicated as $event) {
            $title = '';
            $description = null;
            $dtag = '';
            $stageCount = 0;

            foreach ($event->getTags() as $tag) {
                if (!is_array($tag) || empty($tag)) {
                    continue;
                }
                $k = $tag[0] ?? '';
                match ($k) {
                    'title' => $title = $tag[1] ?? '',
                    'summary' => $description = $tag[1] ?? null,
                    'd' => $dtag = $tag[1] ?? '',
                    'op', 'match', 'not', 'cmp', 'text', 'input' => $stageCount++,
                    default => null,
                };
            }

            if (empty($dtag)) {
                continue;
            }

            // Use content as description fallback
            if (!$description && $event->getContent()) {
                $description = $event->getContent();
            }

            // Use dtag as title fallback
            if (!$title) {
                $title = $dtag;
            }

            $parsedExpressions[] = [
                'event' => $event,
                'title' => $title,
                'description' => $description,
                'content' => $event->getContent(),
                'dtag' => $dtag,
                'stageCount' => $stageCount,
            ];
        }

        // 4. Resolve author metadata
        $authorPubkeys = array_unique(array_map(
            fn($e) => $e['event']->getPubkey(),
            $parsedExpressions
        ));
        $metadataMap = !empty($authorPubkeys)
            ? $this->redisCacheService->getMultipleMetadata($authorPubkeys)
            : [];

        $keyHelper = new Key();

        // 5. Build the final output
        $result = [];
        foreach ($parsedExpressions as $expr) {
            $pubkey = $expr['event']->getPubkey();
            $meta = $metadataMap[$pubkey] ?? null;
            $std = $meta instanceof UserMetadata ? $meta->toStdClass() : $meta;

            try {
                $npub = $keyHelper->convertPublicKeyToBech32($pubkey);
            } catch (\Throwable) {
                $npub = $pubkey;
            }

            $result[] = [
                'title' => $expr['title'],
                'description' => $expr['description'],
                'content' => $expr['content'],
                'stageCount' => $expr['stageCount'],
                'pubkey' => $pubkey,
                'npub' => $npub,
                'dtag' => $expr['dtag'],
                'authorName' => $std->display_name ?? $std->name ?? '',
                'authorPicture' => $std->picture ?? '',
                'createdAt' => $expr['event']->getCreatedAt(),
            ];
        }

        return $result;
    }
}

