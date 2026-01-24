<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Article;
use App\Service\Nostr\NostrClient;
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
            $followedPubkeys = $nostrClient->getUserFollows($pubkeyHex);
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

            // Collect unique author pubkeys
            $authorPubkeys = [];
            foreach ($articles as $article) {
                $authorPubkeys[] = $article->getPubkey();
            }
            $authorPubkeys = array_unique($authorPubkeys);

            // Batch fetch metadata for all authors using NostrClient
            try {
                $metadataEvents = $nostrClient->getMetadataForPubkeys($authorPubkeys);

                foreach ($metadataEvents as $pubkey => $event) {
                    try {
                        $metadata = json_decode($event->content, false);
                        $authorsMetadata[$pubkey] = $metadata;
                    } catch (\Throwable $e) {
                        $logger->warning('Failed to decode author metadata', [
                            'pubkey' => $pubkey,
                            'error' => $e->getMessage()
                        ]);
                        // Create basic metadata object
                        $authorsMetadata[$pubkey] = (object)[
                            'name' => substr($pubkey, 0, 8) . '...'
                        ];
                    }
                }

                // Add fallback metadata for any authors not found
                foreach ($authorPubkeys as $pubkey) {
                    if (!isset($authorsMetadata[$pubkey])) {
                        $authorsMetadata[$pubkey] = (object)[
                            'name' => substr($pubkey, 0, 8) . '...'
                        ];
                    }
                }
            } catch (\Throwable $e) {
                $logger->error('Failed to fetch author metadata', [
                    'error' => $e->getMessage()
                ]);
                // Create basic metadata for all authors as fallback
                foreach ($authorPubkeys as $pubkey) {
                    $authorsMetadata[$pubkey] = (object)[
                        'name' => substr($pubkey, 0, 8) . '...'
                    ];
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

