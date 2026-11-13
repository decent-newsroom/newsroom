<?php

declare(strict_types=1);

namespace App\Controller\Bookshelf;

use App\Bookshelf\BookshelfIndexSource;
use App\Service\EventWorkspace\EventPresentationFactory;
use App\Service\EventWorkspace\PublicationTocPage;
use App\Service\EventWorkspace\PublicationTocTraversalSigner;
use App\Service\Magazine\PublicationIndexClassifier;
use App\Service\UserMuteListService;
use DecentNewsroom\BookshelfBundle\Navigation\BookshelfNavigationTrait;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
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
        private readonly UserMuteListService $muteList,
        #[Autowire('%env(bool:EVENT_WORKSPACE_EXPANSION_ENABLED)%')]
        private readonly bool $expansionEnabled = true,
    ) {}

    #[Route('/bookshelf/index/{eventId}', name: 'bookshelf_index', requirements: ['eventId' => '[0-9a-f]{64}'], methods: ['GET'], priority: 10)]
    public function show(string $eventId, Request $request, PublicationIndexClassifier $classifier): Response
    {
        $muted = $this->mutedPubkeys();
        $event = $this->sources->read($eventId, $muted);

        return $this->render('bookshelf/index.html.twig', [
            'bookshelfNav' => $this->buildBookshelfNav($this->getUser() !== null),
            'presentation' => $this->presentations->fromAuthorizedEvent($event),
            'isBook' => $classifier->isBook($event->getTags()),
            'tocPage' => $this->pages->fromAuthorizedEvent(
                $event, $this->pageNumber($request), routeName: 'bookshelf_index', readerMutedPubkeys: $muted,
            ),
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
        $muted = $this->mutedPubkeys();
        // Signed traversal state grants no permission, even if an ancestor was
        // hidden/deleted/muted after the disclosure was rendered.
        foreach ($path as $id) {
            $this->sources->read($id, $muted);
        }
        $event = $this->sources->read($eventId, $muted);
        if (in_array($eventId, $path, true)
            || $this->presentations->fromAuthorizedEvent($event)->coordinate !== $coordinate) {
            throw $this->createNotFoundException();
        }

        return $this->render('reader/reading_nook/_publication_toc.html.twig', [
            'tocPage' => $this->pages->fromAuthorizedEvent(
                $event, $page, $path, $occurrence, 'bookshelf_index_toc', $muted,
            ),
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

    /** @return list<string> */
    private function mutedPubkeys(): array
    {
        $user = $this->getUser();
        if ($user === null) {
            return [];
        }
        $pubkey = PublicKey::fromBech32($user->getUserIdentifier())?->toHex()
            ?? throw $this->createAccessDeniedException();

        return $this->muteList->getMutedPubkeysStrict($pubkey);
    }
}
