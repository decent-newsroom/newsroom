<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\NostrClient;
use App\Service\RedisCacheService;
use App\Util\NostrKeyUtil;
use Elastica\Query\Terms;
use Exception;
use FOS\ElasticaBundle\Finder\FinderInterface;
use swentel\nostr\Key\Key;
use swentel\nostr\Nip19\Nip19Helper;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class AuthorController extends AbstractController
{
    /**
     * @throws Exception
     */
    #[Route('/p/{npub}/media', name: 'author-media', requirements: ['npub' => '^npub1.*'])]
    public function media($npub, NostrClient $nostrClient, RedisCacheService $redisCacheService, NostrKeyUtil $keyUtil): Response
    {

        $author = $redisCacheService->getMetadata($keyUtil->npubToHex($npub));

        // Use paginated cached media events - fetches 200 from relays, serves first 24
        $paginatedData = $redisCacheService->getMediaEventsPaginated($npub, 1, 24);
        $mediaEvents = $paginatedData['events'];

        // Encode event IDs as note1... for each event
        foreach ($mediaEvents as $event) {
            $nip19 = new Nip19Helper();
            $event->noteId = $nip19->encodeNote($event->id);
        }

        return $this->render('pages/author-media.html.twig', [
            'author' => $author,
            'npub' => $npub,
            'pictureEvents' => $mediaEvents,
            'hasMore' => $paginatedData['hasMore'],
            'total' => $paginatedData['total'],
            'is_author_profile' => true,
        ]);
    }

    /**
     * AJAX endpoint to load more media events
     */
    #[Route('/p/{npub}/media/load-more', name: 'author-media-load-more', requirements: ['npub' => '^npub1.*'])]
    public function mediaLoadMore($npub, Request $request, RedisCacheService $redisCacheService): Response
    {
        $page = $request->query->getInt('page', 2); // Default to page 2

        // Get paginated data from cache - 24 items per page
        $paginatedData = $redisCacheService->getMediaEventsPaginated($npub, $page, 24);
        $mediaEvents = $paginatedData['events'];

        // Encode event IDs as note1... for each event
        foreach ($mediaEvents as $event) {
            $nip19 = new Nip19Helper();
            $event->noteId = $nip19->encodeNote($event->id);
        }

        return $this->json([
            'events' => array_map(function($event) {
                return [
                    'id' => $event->id,
                    'noteId' => $event->noteId,
                    'content' => $event->content ?? '',
                    'created_at' => $event->created_at,
                    'kind' => $event->kind,
                    'tags' => $event->tags ?? [],
                ];
            }, $mediaEvents),
            'hasMore' => $paginatedData['hasMore'],
            'page' => $paginatedData['page'],
            'total' => $paginatedData['total'],
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

        $author = $redisCacheService->getMetadata($pubkey);
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
