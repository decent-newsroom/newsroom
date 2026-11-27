<?php

declare(strict_types=1);

namespace App\Controller\Bookshelf;

use App\Bookshelf\FeaturedBookshelf;
use DecentNewsroom\BookshelfBundle\Navigation\BookshelfNavigationTrait;
use DecentNewsroom\BookshelfBundle\Service\Bookshelf\BookshelfDirectoryService;
use DecentNewsroom\BookshelfBundle\Service\Mercury\Exception\MercuryApiException;
use DecentNewsroom\BookshelfBundle\Service\Mercury\MercuryBookService;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class LandingController extends AbstractController
{
    use BookshelfNavigationTrait;

    #[Route('/bookshelf', name: 'bookshelf_landing', methods: ['GET'], priority: 20)]
    public function index(
        Request $request,
        MercuryBookService $bookService,
        BookshelfDirectoryService $directoryService,
        FeaturedBookshelf $featured,
        LoggerInterface $logger,
    ): Response {
        $query = trim($request->query->getString('q'));
        $books = [];
        $available = true;
        $searched = $query !== '';
        $queryTooShort = $searched && mb_strlen($query) < 2;
        if ($searched && !$queryTooShort) {
            try {
                $books = $bookService->search($query);
            } catch (MercuryApiException $exception) {
                $available = false;
                $logger->warning('Bookshelf search failed.', ['exception' => $exception]);
            }
        }
        $pubkey = $this->readerPubkey();
        $tags = $pubkey === null ? [] : $directoryService->getEditableTagsForUser($pubkey);
        $coordinates = array_values(array_filter(array_column($directoryService->extractBookReferences($tags), 'coordinate')));

        return $this->render('@Bookshelf/pages/bookshelf.html.twig', [
            'bookshelfNav' => $this->buildBookshelfNav($this->getUser() !== null),
            'query' => $query, 'books' => $books, 'searched' => $searched,
            'available' => $available, 'queryTooShort' => $queryTooShort,
            'directoryIdentifier' => BookshelfDirectoryService::IDENTIFIER,
            'directoryTags' => $tags, 'directoryCoordinates' => $coordinates,
            'featured' => $featured->page(preview: true),
        ], new Response(headers: ['Cache-Control' => 'private, no-store']));
    }

    #[Route('/bookshelf/featured', name: 'bookshelf_featured', methods: ['GET'], priority: 20)]
    public function directory(Request $request, FeaturedBookshelf $featured): Response
    {
        $rawPage = $request->query->all()['page'] ?? '1';
        $eventId = $request->query->all()['eventId'] ?? null;
        $page = is_scalar($rawPage) ? filter_var($rawPage, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => intdiv(PHP_INT_MAX, FeaturedBookshelf::PAGE_SIZE)],
        ]) : false;
        if ($page === false || ($eventId !== null && (!is_string($eventId) || preg_match('/^[0-9a-f]{64}$/D', $eventId) !== 1))
            || ($page > 1 && $eventId === null)) {
            throw new BadRequestHttpException('Invalid featured directory page.');
        }
        $directory = $featured->page($page, $eventId);
        if ($directory['state'] === 'unconfigured') {
            throw $this->createNotFoundException();
        }

        return $this->render('bookshelf/featured.html.twig', [
            'bookshelfNav' => $this->buildBookshelfNav($this->getUser() !== null),
            'featured' => $directory,
        ], new Response(headers: ['Cache-Control' => 'private, no-store']));
    }

    private function readerPubkey(): ?string
    {
        $user = $this->getUser();

        return $user === null ? null : (PublicKey::fromBech32($user->getUserIdentifier())?->toHex()
            ?? throw $this->createAccessDeniedException());
    }
}
