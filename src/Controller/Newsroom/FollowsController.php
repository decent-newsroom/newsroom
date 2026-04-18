<?php

declare(strict_types=1);

namespace App\Controller\Newsroom;

use App\Dto\UserMetadata;
use App\Entity\Article;
use App\Enum\KindsEnum;
use App\Repository\ArticleRepository;
use App\Repository\EventRepository;
use App\Service\Cache\RedisCacheService;
use App\Service\Nostr\UserProfileService;
use App\Util\NostrKeyUtil;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class FollowsController extends AbstractController
{
    #[Route('/follows', name: 'follows')]
    public function index(
        ArticleRepository $articleRepository,
        EventRepository $eventRepository,
        UserProfileService $userProfileService,
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
            $pubkeyHex = NostrKeyUtil::npubToHex($user->getUserIdentifier());
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

        // Resolve followed pubkeys from the local DB first.
        // If kind 3 is missing (async pipeline hasn't persisted it yet),
        // fall back to UserProfileService which fetches from the local relay
        // (+ user's NIP-65 relays) and persists the event for next time.
        $followedPubkeys = [];
        try {
            $followsEvent = $eventRepository->findLatestByPubkeyAndKind($pubkeyHex, KindsEnum::FOLLOWS->value);
            if ($followsEvent !== null) {
                foreach ($followsEvent->getTags() as $tag) {
                    if (is_array($tag) && ($tag[0] ?? '') === 'p' && isset($tag[1])) {
                        $followedPubkeys[] = $tag[1];
                    }
                }
            } else {
                $logger->info('Kind 3 not in DB, attempting relay backfill', ['pubkey' => substr($pubkeyHex, 0, 8) . '...']);
                $followedPubkeys = $userProfileService->getFollows($pubkeyHex);
            }
            $logger->info('Loaded follow list', [
                'user_pubkey' => $pubkeyHex,
                'follows_count' => count($followedPubkeys)
            ]);
        } catch (\Throwable $e) {
            $logger->error('Failed to load follow list from DB', [
                'error' => $e->getMessage(),
                'pubkey' => $pubkeyHex
            ]);

            return $this->render('follows/index.html.twig', [
                'isLoggedIn' => true,
                'articles' => [],
                'authorsMetadata' => [],
                'error' => 'Unable to load your follow list'
            ]);
        }

        $articles = [];
        $authorsMetadata = [];

        // If user follows people, get their articles from the local DB
        if (!empty($followedPubkeys)) {
            $articles = $articleRepository->findLatestByPubkeys($followedPubkeys, 50);

            // Collect unique author pubkeys and batch-resolve metadata
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
            'followCount' => count($followedPubkeys)
        ]);
    }
}

