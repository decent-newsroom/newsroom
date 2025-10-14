<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Article;
use App\Message\FetchAuthorArticlesMessage;
use App\Repository\ArticleRepository;
use App\Service\NostrClient;
use App\Service\RedisCacheService;
use App\Util\NostrKeyUtil;
use Doctrine\ORM\EntityManagerInterface;
use Elastica\Query\BoolQuery;
use Elastica\Collapse;
use Elastica\Query\Term;
use Elastica\Query\Terms;
use Exception;
use FOS\ElasticaBundle\Finder\FinderInterface;
use Psr\Cache\InvalidArgumentException;
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
     * @throws Exception
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
     * @throws Exception
     */
    #[Route('/p/{pubkey}', name: 'author-redirect')]
    public function authorRedirect($pubkey): Response
    {
        $keys = new Key();
        $npub = $keys->convertPublicKeyToBech32($pubkey);
        return $this->redirectToRoute('author-profile', ['npub' => $npub]);
    }

    #[Route('/articles/render', name: 'render_articles', methods: ['POST'], options: ['csrf_protection' => false])]
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
