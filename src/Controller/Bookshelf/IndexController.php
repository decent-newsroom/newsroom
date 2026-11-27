<?php

declare(strict_types=1);

namespace App\Controller\Bookshelf;

use App\Bookshelf\BookContainingIndexes;
use App\Bookshelf\BookshelfIndexSource;
use App\Bookshelf\BookshelfTocArticles;
use App\Dto\EventWorkspace\EventPresentation;
use App\Dto\EventWorkspace\EventReferenceRole;
use App\Dto\UserMetadata;
use App\Repository\EventRepository;
use App\Service\Cache\RedisCacheService;
use App\Service\EventWorkspace\EventPresentationFactory;
use App\Service\EventWorkspace\PublicationTocPage;
use App\Service\EventWorkspace\PublicationTocTraversalSigner;
use App\Service\Magazine\PublicationIndexClassifier;
use DecentNewsroom\BookshelfBundle\Navigation\BookshelfNavigationTrait;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

/** An explicit Bookshelf viewer; deliberately independent of magazine classification. */
final class IndexController extends AbstractController
{
    use BookshelfNavigationTrait;

    public function __construct(
        private readonly BookshelfIndexSource $sources,
        private readonly EventPresentationFactory $presentations,
        private readonly PublicationTocPage $pages,
        private readonly PublicationTocTraversalSigner $signer,
        private readonly EventRepository $events,
        private readonly RedisCacheService $profileCache,
        private readonly BookContainingIndexes $containingIndexes,
        private readonly BookshelfTocArticles $tocArticles,
        #[Autowire('%env(bool:EVENT_WORKSPACE_EXPANSION_ENABLED)%')]
        private readonly bool $expansionEnabled = true,
    ) {}

    #[Route('/bookshelf/index/{eventId}', name: 'bookshelf_index', requirements: ['eventId' => '[0-9a-f]{64}'], methods: ['GET'], priority: 10)]
    public function show(string $eventId, Request $request, PublicationIndexClassifier $classifier): Response
    {
        $event = $this->sources->read($eventId);
        $pubkey = strtolower($event->getPubkey());
        $metadataEvent = $this->events->findLatestMetadataByPubkey($pubkey);
        $authorMetadata = $metadataEvent === null
            ? $this->profileCache->getMetadata($pubkey, refreshOnMiss: false)
            : UserMetadata::fromMetadataEvent($metadataEvent);
        $presentation = $this->presentations->fromAuthorizedEvent($event);

        return $this->render('bookshelf/index.html.twig', [
            'bookshelfNav' => $this->buildBookshelfNav($this->getUser() !== null),
            'presentation' => $presentation,
            'authorMetadata' => $authorMetadata->toStdClass(),
            'tocHeading' => $this->tocHeading($presentation),
            'tocPageNumber' => $this->pageNumber($request),
            'isBook' => $classifier->isBook($event->getTags()),
            'containingIndexes' => $this->containingIndexes->forBook($event),
        ], new Response(headers: ['Cache-Control' => 'private, no-store']));
    }

    #[Route('/bookshelf/index/{eventId}/contents', name: 'bookshelf_index_contents', requirements: ['eventId' => '[0-9a-f]{64}'], methods: ['GET'], priority: 10)]
    public function contents(string $eventId, Request $request): Response
    {
        $page = $this->pageNumber($request);
        $event = $this->sources->read($eventId);

        return $this->render('reader/reading_nook/_publication_toc.html.twig', [
            'tocPage' => $this->tocArticles->enrich($this->pages->fromAuthorizedEvent(
                $event, $page, routeName: 'bookshelf_index_contents',
                separateFeaturedArticle: true, filterDeletedTargets: false,
            )),
            'tocNestedRoute' => 'bookshelf_index_toc',
            'tocBookshelfLinks' => true,
        ], new Response(headers: ['Cache-Control' => 'private, no-store']));
    }

    #[Route('/bookshelf/index/{eventId}/toc', name: 'bookshelf_index_toc', requirements: ['eventId' => '[0-9a-f]{64}'], methods: ['GET'], priority: 10)]
    public function toc(string $eventId, Request $request): Response
    {
        if (!$this->expansionEnabled) {
            throw $this->createNotFoundException();
        }
        $page = $this->pageNumber($request);
        $query = $request->query->all();
        $rawPath = $query['path'] ?? null;
        $occurrence = $query['occurrence'] ?? null;
        $coordinate = $query['coordinate'] ?? null;
        $signature = $query['signature'] ?? null;
        if (!is_string($rawPath) || strlen($rawPath) > PublicationTocPage::MAX_EXPANSION_DEPTH * 65
            || !is_string($occurrence) || preg_match('/^[0-9a-f]{64}$/', $occurrence) !== 1
            || !is_string($coordinate) || !is_string($signature)) {
            throw new BadRequestHttpException('Invalid nested TOC path.');
        }
        $path = explode(',', $rawPath);
        if (count($path) >= PublicationTocPage::MAX_EXPANSION_DEPTH
            || count(array_unique($path)) !== count($path)) {
            throw new BadRequestHttpException('Invalid nested TOC path.');
        }
        foreach ($path as $id) {
            if (preg_match('/^[0-9a-f]{64}$/', $id) !== 1) {
                throw new BadRequestHttpException('Invalid nested TOC path.');
            }
        }
        if (!$this->signer->isValid($eventId, $page, $path, $occurrence, $coordinate, $signature)) {
            throw new BadRequestHttpException('Invalid nested TOC signature.');
        }
        // Reauthorize each ancestor using the current public-index access rules.
        foreach ($path as $id) {
            $this->sources->read($id);
        }
        $event = $this->sources->read($eventId);
        if (in_array($eventId, $path, true)
            || $this->presentations->fromAuthorizedEvent($event)->coordinate !== $coordinate) {
            throw $this->createNotFoundException();
        }

        return $this->render('reader/reading_nook/_publication_toc.html.twig', [
            'tocPage' => $this->tocArticles->enrich($this->pages->fromAuthorizedEvent(
                $event, $page, $path, $occurrence, 'bookshelf_index_toc',
                separateFeaturedArticle: true, filterDeletedTargets: false,
            )),
            'tocNestedRoute' => 'bookshelf_index_toc',
            'tocBookshelfLinks' => true,
        ], new Response(headers: ['Cache-Control' => 'private, no-store']));
    }

    private function pageNumber(Request $request): int
    {
        $page = filter_var($request->query->get('page', '1'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        if ($page === false) {
            throw new BadRequestHttpException('Invalid page.');
        }

        return $page;
    }

    private function tocHeading(EventPresentation $presentation): string
    {
        $leafKinds = [];
        $articleCount = 0;
        $hasIndex = false;
        $onlyArticlesAndIndexes = true;
        foreach ($presentation->references as $reference) {
            if ($reference->role !== EventReferenceRole::Content) {
                continue;
            }
            $articleCount += $reference->targetKind === 30023 ? 1 : 0;
            $hasIndex = $hasIndex || $reference->targetKind === 30040;
            $onlyArticlesAndIndexes = $onlyArticlesAndIndexes && in_array($reference->targetKind, [30023, 30040], true);
            if ($reference->targetKind === null || $reference->targetKind === 30040) {
                continue;
            }
            $leafKinds[$reference->targetKind] = true;
        }

        if ($articleCount === 1 && $hasIndex && $onlyArticlesAndIndexes) {
            return 'bookshelf.publication.contents';
        }

        return match (array_keys($leafKinds)) {
            [30023] => 'bookshelf.publication.articles',
            [30041], [] => 'bookshelf.publication.chapters',
            default => 'bookshelf.publication.contents',
        };
    }
}
