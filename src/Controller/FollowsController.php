<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Article;
use App\Service\Cache\RedisCacheService;
use App\Service\Nostr\NostrClient;
use App\Dto\UserMetadata;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use swentel\nostr\Key\Key;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class FollowsController extends AbstractController
{
    #[Route('/follows', name: 'follows')]
    public function index(
        EntityManagerInterface $em,
        NostrClient $nostrClient,
        RedisCacheService $redisCacheService,
        LoggerInterface $logger
    ): Response
    {
        $user = $this->getUser();

        // If user is not logged in, show a notice
        if (!$user) {
            return $this->render('follows/index.html.twig', [
                'isLoggedIn' => false,
                'articles' => [],
                'authorsMetadata' => [],
            ]);
        }

        // Get user's pubkey in hex format
        $pubkeyHex = null;
        try {
            $key = new Key();
            $pubkeyHex = $key->convertToHex($user->getUserIdentifier());
        } catch (\Throwable $e) {
            $logger->error('Failed to convert user npub to hex', [
                'error' => $e->getMessage(),
                'npub' => $user->getUserIdentifier()
            ]);

            return $this->render('follows/index.html.twig', [
                'isLoggedIn' => true,
                'articles' => [],
                'authorsMetadata' => [],
                'error' => 'Unable to process user credentials'
            ]);
        }

        // Fetch the user's follow list from relays using NostrClient
        $followedPubkeys = [];
        try {
            $followedPubkeys = $nostrClient->getUserFollows($pubkeyHex, $user->getRelays()['all'] ?? null);
            $logger->info('Fetched follow list from relays', [
                'user_pubkey' => $pubkeyHex,
                'follows_count' => count($followedPubkeys)
            ]);
        } catch (\Throwable $e) {
            $logger->error('Failed to fetch follow list from relays', [
                'error' => $e->getMessage(),
                'pubkey' => $pubkeyHex
            ]);

            return $this->render('follows/index.html.twig', [
                'isLoggedIn' => true,
                'articles' => [],
                'authorsMetadata' => [],
                'error' => 'Unable to fetch your follow list from relays'
            ]);
        }

        $articles = [];
        $authorsMetadata = [];

        // If user follows people, get their articles
        if (!empty($followedPubkeys)) {
            $articleRepo = $em->getRepository(Article::class);

            // Query articles from followed authors, ordered by creation date
            $qb = $articleRepo->createQueryBuilder('a');
            $qb->where($qb->expr()->in('a.pubkey', ':pubkeys'))
               ->setParameter('pubkeys', $followedPubkeys)
               ->orderBy('a.createdAt', 'DESC')
               ->setMaxResults(50); // Limit to latest 50 articles

            $articles = $qb->getQuery()->getResult();

            // Deduplicate by slug+pubkey — newest revision wins
            $seen = [];
            $articles = array_filter($articles, function (Article $a) use (&$seen) {
                $key = $a->getPubkey() . ':' . $a->getSlug();
                if (isset($seen[$key])) {
                    return false;
                }
                $seen[$key] = true;
                return true;
            });

            // Collect unique author pubkeys and batch-resolve metadata
            // via cache → DB → async dispatch (no blocking relay calls)
            $authorPubkeys = array_unique(array_map(
                fn(Article $a) => $a->getPubkey(),
                $articles
            ));

            if (!empty($authorPubkeys)) {
                $metadataMap = $redisCacheService->getMultipleMetadata($authorPubkeys);
                foreach ($metadataMap as $pubkey => $metadata) {
                    $authorsMetadata[$pubkey] = $metadata instanceof UserMetadata
                        ? $metadata->toStdClass()
                        : $metadata;
                }
            }
        }

        return $this->render('follows/index.html.twig', [
            'isLoggedIn' => true,
            'articles' => $articles,
            'authorsMetadata' => $authorsMetadata,
            'followCount' => count($followedPubkeys),
        ]);
    }
}

