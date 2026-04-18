<?php

declare(strict_types=1);

namespace App\Controller\Media;

use App\Entity\MediaPostCache;
use App\Util\NostrKeyUtil;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class UserMediaController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    /**
     * List the current user's media posts (kinds 20, 21, 22) from the local DB cache.
     */
    #[Route('/api/user-media-posts', name: 'api_user_media_posts', methods: ['GET'])]
    public function listMediaPosts(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['status' => 'error', 'message' => 'Not authenticated'], 401);
        }

        $limit = min($request->query->getInt('limit', 20), 100);
        $offset = max($request->query->getInt('offset', 0), 0);

        try {
            $hexPubkey = NostrKeyUtil::npubToHex($user->getUserIdentifier());
        } catch (\Throwable) {
            return new JsonResponse(['posts' => [], 'total' => 0, 'limit' => $limit, 'offset' => $offset]);
        }

        $qb = $this->entityManager->createQueryBuilder();

        // Count
        $total = (int) $qb->select('COUNT(p.eventId)')
            ->from(MediaPostCache::class, 'p')
            ->where('p.pubkey = :pubkey')
            ->andWhere('p.kind IN (:kinds)')
            ->setParameter('pubkey', $hexPubkey)
            ->setParameter('kinds', [20, 21, 22, 34235, 34236])
            ->getQuery()
            ->getSingleScalarResult();

        // Fetch
        $posts = $this->entityManager->createQueryBuilder()
            ->select('p')
            ->from(MediaPostCache::class, 'p')
            ->where('p.pubkey = :pubkey')
            ->andWhere('p.kind IN (:kinds)')
            ->setParameter('pubkey', $hexPubkey)
            ->setParameter('kinds', [20, 21, 22, 34235, 34236])
            ->orderBy('p.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();

        $items = array_map(fn(MediaPostCache $p) => [
            'event_id' => $p->getEventId(),
            'kind' => $p->getKind(),
            'title' => $p->getTitle(),
            'content' => $p->getContent() ? mb_substr($p->getContent(), 0, 200) : null,
            'primary_url' => $p->getPrimaryUrl(),
            'preview_url' => $p->getPreviewUrl(),
            'duration' => $p->getDuration(),
            'created_at' => $p->getCreatedAt(),
        ], $posts);

        return new JsonResponse([
            'posts' => $items,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
        ]);
    }
}

