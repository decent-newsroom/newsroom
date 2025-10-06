<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\NostrClient;
use App\Service\RedisCacheService;
use Elastica\Query\Terms;
use Exception;
use FOS\ElasticaBundle\Finder\FinderInterface;
use swentel\nostr\Key\Key;
use swentel\nostr\Nip19\Nip19Helper;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class AuthorController extends AbstractController
{
    /**
     * @throws Exception
     */
    #[Route('/p/{npub}/media', name: 'author-media', requirements: ['npub' => '^npub1.*'])]
    public function media($npub, NostrClient $nostrClient, RedisCacheService $redisCacheService): Response
    {
        $author = $redisCacheService->getMetadata($npub);

        // Retrieve picture events (kind 20) for the author
        try {
            $pictureEvents = $nostrClient->getPictureEventsForPubkey($npub, 30);
        } catch (Exception $e) {
            $pictureEvents = [];
        }

        // Retrieve video shorts (kind 22) for the author
        try {
            $videoShorts = $nostrClient->getVideoShortsForPubkey($npub, 30);
        } catch (Exception $e) {
            $videoShorts = [];
        }

        // Retrieve normal videos (kind 21) for the author
        try {
            $normalVideos = $nostrClient->getNormalVideosForPubkey($npub, 30);
        } catch (Exception $e) {
            $normalVideos = [];
        }

        // Merge picture events, video shorts, and normal videos
        $mediaEvents = array_merge($pictureEvents, $videoShorts, $normalVideos);

        // Deduplicate by event ID
        $uniqueEvents = [];
        foreach ($mediaEvents as $event) {
            if (!isset($uniqueEvents[$event->id])) {
                $uniqueEvents[$event->id] = $event;
            }
        }

        // Convert back to indexed array and sort by date (newest first)
        $mediaEvents = array_values($uniqueEvents);
        usort($mediaEvents, function ($a, $b) {
            return $b->created_at <=> $a->created_at;
        });

        // Encode event IDs as note1... for each event
        foreach ($mediaEvents as $event) {
            $nip19 = new Nip19Helper(); // The NIP-19 helper class.
            $event->noteId = $nip19->encodeNote($event->id);
        }

        return $this->render('pages/author-media.html.twig', [
            'author' => $author,
            'npub' => $npub,
            'pictureEvents' => $mediaEvents,
            'is_author_profile' => true,
        ]);
    }

    /**
     * @throws Exception
     */
    #[Route('/p/{npub}/about', name: 'author-about', requirements: ['npub' => '^npub1.*'])]
    public function about($npub, RedisCacheService $redisCacheService): Response
    {
        $keys = new Key();
        $pubkey = $keys->convertToHex($npub);

        // Get metadata with raw event for debugging
        $profileData = $redisCacheService->getMetadataWithRawEvent($npub);
        $author = $profileData['metadata'];
        $rawEvent = $profileData['rawEvent'];

        return $this->render('pages/author-about.html.twig', [
            'author' => $author,
            'npub' => $npub,
            'pubkey' => $pubkey,
            'rawEvent' => $rawEvent,
            'is_author_profile' => true,
        ]);
    }

    /**
     * @throws Exception
     */
    #[Route('/p/{npub}', name: 'author-profile', requirements: ['npub' => '^npub1.*'])]
    public function index($npub, NostrClient $nostrClient, RedisCacheService $redisCacheService, FinderInterface $finder): Response
    {
        $keys = new Key();
        $pubkey = $keys->convertToHex($npub);

        $author = $redisCacheService->getMetadata($npub);
        // Retrieve long-form content for the author
        try {
            $list = $nostrClient->getLongFormContentForPubkey($npub);
        } catch (Exception $e) {
            $list = [];
        }
        // Also look for articles in the Elastica index
        $query = new Terms('pubkey', [$pubkey]);
        $list = array_merge($list, $finder->find($query, 25));

        // Sort articles by date
        usort($list, function ($a, $b) {
            return $b->getCreatedAt() <=> $a->getCreatedAt();
        });

        $articles = [];
        // Deduplicate by slugs
        foreach ($list as $item) {
            if (!key_exists((string) $item->getSlug(), $articles)) {
                $articles[(string) $item->getSlug()] = $item;
            }
        }

        return $this->render('pages/author.html.twig', [
            'author' => $author,
            'npub' => $npub,
            'articles' => $articles,
            'is_author_profile' => true,
        ]);
    }

    /**
     * @throws Exception
     */
    #[Route('/p/{pubkey}', name: 'author-redirect')]
    public function authorRedirect($pubkey): Response
    {
        $keys = new Key();
        $npub = $keys->convertPublicKeyToBech32($pubkey);
        return $this->redirectToRoute('author-profile', ['npub' => $npub]);
    }
}
