<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Article;
use App\Entity\Event;
use App\Enum\KindsEnum;
use App\Message\FetchAuthorArticlesMessage;
use App\Service\NostrClient;
use App\Service\RedisCacheService;
use App\Util\NostrKeyUtil;
use Doctrine\ORM\EntityManagerInterface;
use Elastica\Query\BoolQuery;
use Elastica\Collapse;
use Elastica\Query\Term;
use Exception;
use FOS\ElasticaBundle\Finder\FinderInterface;
use Psr\Cache\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use swentel\nostr\Key\Key;
use swentel\nostr\Nip19\Nip19Helper;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Serializer\SerializerInterface;

class AuthorController extends AbstractController
{

    /**
     * Lists
     * @throws Exception
     */
    #[Route('/p/{npub}/list/{slug}', name: 'reading-list')]
    public function readingList($npub, $slug,
                                EntityManagerInterface $em,
                                NostrKeyUtil $keyUtil,
                                LoggerInterface $logger): Response
    {
        // Convert npub to hex pubkey
        $pubkey = $keyUtil->npubToHex($npub);
        $logger->info(sprintf('Reading list: pubkey=%s, slug=%s', $pubkey, $slug));

        // Find reading list by pubkey+slug, kind 30040 directly from database
        $repo = $em->getRepository(Event::class);
        $lists = $repo->findBy(['pubkey' => $pubkey, 'kind' => KindsEnum::PUBLICATION_INDEX], ['created_at' => 'DESC']);
        // Filter by slug
        $list = null;
        foreach ($lists as $ev) {
            if (!$ev instanceof Event) continue;

            $eventSlug = $ev->getSlug();

            if ($eventSlug === $slug) {
                $list = $ev;
                break; // Found the latest one
            }
        }

        if (!$list) {
            throw $this->createNotFoundException('Reading list not found');
        }

        // fetch articles listed in the list's a tags
        $coordinates = []; // Store full coordinates (kind:author:slug)
        // Extract category metadata and article coordinates
        foreach ($list->getTags() as $tag) {
            if ($tag[0] === 'a') {
                $coordinates[] = $tag[1]; // Store the full coordinate
            }
        }

        $articles = [];
        if (count($coordinates) > 0) {
            $articleRepo = $em->getRepository(Article::class);

            // Query database directly for each coordinate
            foreach ($coordinates as $coord) {
                $parts = explode(':', $coord, 3);
                if (count($parts) === 3) {
                    [$kind, $author, $articleSlug] = $parts;

                    // Find the most recent event matching this coordinate
                    $events = $articleRepo->findBy([
                        'slug' => $articleSlug,
                        'pubkey' => $author
                    ], ['createdAt' => 'DESC']);

                    // Filter by slug and get the latest
                    foreach ($events as $event) {
                        if ($event->getSlug() === $articleSlug) {
                            $articles[] = $event;
                            break; // Take the first match (most recent if ordered)
                        }
                    }
                }
            }
        }

        return $this->render('pages/list.html.twig', [
            'list' => $list,
            'articles' => $articles,
        ]);
    }

    /**
     * Multimedia
     * @throws Exception|InvalidArgumentException
     */
    #[Route('/p/{npub}/media', name: 'author-media', requirements: ['npub' => '^npub1.*'])]
    public function media($npub, NostrClient $nostrClient, RedisCacheService $redisCacheService, NostrKeyUtil $keyUtil): Response
    {

        $author = $redisCacheService->getMetadata($keyUtil->npubToHex($npub));

        // Use paginated cached media events - fetches 200 from relays, serves first 24
        $paginatedData = $redisCacheService->getMediaEventsPaginated($keyUtil->npubToHex($npub), 1, 24);
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
     * @throws Exception
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
     * Author profile and articles
     * @throws Exception
     * @throws ExceptionInterface
     * @throws InvalidArgumentException
     */
    #[Route('/p/{npub}', name: 'author-profile', requirements: ['npub' => '^npub1.*'])]
    public function index($npub, RedisCacheService $redisCacheService, FinderInterface $finder,
                          MessageBusInterface $messageBus): Response
    {
        $keys = new Key();
        $pubkey = $keys->convertToHex($npub);

        $author = $redisCacheService->getMetadata($pubkey);

        // Get articles using Elasticsearch with collapse on slug
        $boolQuery = new BoolQuery();
        $boolQuery->addMust(new Term(['pubkey' => $pubkey]));
        $query = new \Elastica\Query($boolQuery);
        $query->setSort(['createdAt' => ['order' => 'desc']]);
        $collapse = new Collapse();
        $collapse->setFieldname('slug');
        $query->setCollapse($collapse);
        $articles = $finder->find($query);

        // Get latest createdAt for dispatching fetch message
        if (!empty($articles)) {
            $latest = $articles[0]->getCreatedAt()->getTimestamp();
            // Dispatch async message to fetch new articles since latest + 1
            $messageBus->dispatch(new FetchAuthorArticlesMessage($pubkey, $latest + 1));
        } else {
            // No articles, fetch all
            $messageBus->dispatch(new FetchAuthorArticlesMessage($pubkey, 0));
        }


        return $this->render('pages/author.html.twig', [
            'author' => $author,
            'npub' => $npub,
            'pubkey' => $pubkey,
            'articles' => $articles,
            'is_author_profile' => true,
        ]);
    }

    /**
     * Redirect from /p/{pubkey} to /p/{npub}
     * @throws Exception
     */
    #[Route('/p/{pubkey}', name: 'author-redirect')]
    public function authorRedirect($pubkey): Response
    {
        $keys = new Key();
        $npub = $keys->convertPublicKeyToBech32($pubkey);
        return $this->redirectToRoute('author-profile', ['npub' => $npub]);
    }

    /**
     * AJAX endpoint to render articles from JSON input
     * @param Request $request
     * @param SerializerInterface $serializer
     * @return Response
     */
    #[Route('/articles/render', name: 'render_articles', options: ['csrf_protection' => false], methods: ['POST'])]
    public function renderArticles(Request $request, SerializerInterface $serializer): Response
    {

        $data = json_decode($request->getContent(), true);
        $articlesJson = json_encode($data['articles'] ?? []);
        $articles = $serializer->deserialize($articlesJson, Article::class.'[]', 'json');

        // Render the articles using the template
        return $this->render('articles.html.twig', [
            'articles' => $articles
        ]);
    }
}
