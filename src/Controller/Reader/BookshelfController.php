<?php

declare(strict_types=1);

namespace App\Controller\Reader;

use App\Helper\NavigationBuilderTrait;
use App\Service\Bookshelf\BookshelfDirectoryService;
use App\Service\Mercury\MercuryApiException;
use App\Service\Mercury\MercuryBookService;
use App\Util\AsciiDoc\AsciiDocConverter;
use App\Util\NostrKeyUtil;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class BookshelfController extends AbstractController
{
    use NavigationBuilderTrait;

    #[Route('/bookshelf', name: 'bookshelf', methods: ['GET'])]
    public function index(
        Request $request,
        MercuryBookService $bookService,
        BookshelfDirectoryService $directoryService,
    ): Response
    {
        $query = trim((string) $request->query->get('q', ''));
        $books = [];
        $searched = $query !== '';
        $available = true;
        $queryTooShort = false;

        if ($searched) {
            if (mb_strlen($query) < 2) {
                $queryTooShort = true;
            } else {
                try {
                    $books = $bookService->search($query);
                } catch (MercuryApiException) {
                    $available = false;
                }
            }
        }

        return $this->render('pages/bookshelf.html.twig', [
            'bookshelfNav' => $this->buildBookshelfNav($this->getUser() !== null),
            ...$this->directoryContext($directoryService),
            'query' => $query,
            'searched' => $searched,
            'queryTooShort' => $queryTooShort,
            'available' => $available,
            'books' => $books,
        ]);
    }

    #[Route(
        '/bookshelf/{id}',
        name: 'bookshelf_read',
        requirements: ['id' => '[a-fA-F0-9]{64}'],
        methods: ['GET'],
    )]
    public function read(
        string $id,
        Request $request,
        MercuryBookService $bookService,
        BookshelfDirectoryService $directoryService,
        AsciiDocConverter $asciiDocConverter,
        LoggerInterface $logger,
    ): Response {
        $query = trim((string) $request->query->get('q', ''));
        $directoryContext = $this->directoryContext($directoryService);

        try {
            $book = $bookService->getBook(strtolower($id));
        } catch (MercuryApiException $exception) {
            $logger->warning('Mercury bookshelf request failed.', [
                'event_id' => $id,
                'error' => $exception->getMessage(),
            ]);

            return $this->render('bookshelf/read.html.twig', [
                'bookshelfNav' => $this->buildBookshelfNav($this->getUser() !== null),
                ...$directoryContext,
                'book' => null,
                'available' => false,
                'query' => $query,
            ], new Response(status: Response::HTTP_SERVICE_UNAVAILABLE));
        }

        if ($book === null) {
            return $this->render('bookshelf/read.html.twig', [
                'bookshelfNav' => $this->buildBookshelfNav($this->getUser() !== null),
                ...$directoryContext,
                'book' => null,
                'available' => true,
                'query' => $query,
            ], new Response(status: Response::HTTP_NOT_FOUND));
        }

        foreach ($book['chapters'] as &$chapter) {
            if (!$chapter['available']) {
                $chapter['html'] = null;
                continue;
            }

            try {
                $chapter['html'] = $asciiDocConverter->convert((string) $chapter['content']);
            } catch (\Throwable $exception) {
                $logger->warning('Mercury chapter conversion failed.', [
                    'event_id' => $chapter['id'],
                    'error' => $exception->getMessage(),
                ]);
                $chapter['html'] = '<pre>' . htmlspecialchars(
                    (string) $chapter['content'],
                    ENT_QUOTES | ENT_SUBSTITUTE,
                    'UTF-8',
                ) . '</pre>';
            }
        }
        unset($chapter);

        return $this->render('bookshelf/read.html.twig', [
            'bookshelfNav' => $this->buildBookshelfNav($this->getUser() !== null),
            ...$directoryContext,
            'book' => $book,
            'available' => true,
            'query' => $query,
        ]);
    }

    /**
     * @return array{
     *     directoryTags: array<int, array<int, string>>,
     *     directoryCoordinates: string[],
     *     directoryIdentifier: string
     * }
     */
    private function directoryContext(BookshelfDirectoryService $directoryService): array
    {
        $tags = [];
        $coordinates = [];
        $user = $this->getUser();

        if ($user !== null) {
            $pubkey = NostrKeyUtil::npubToHex($user->getUserIdentifier());
            $tags = $directoryService->getEditableTagsForUser($pubkey);
            foreach ($directoryService->extractBookReferences($tags) as $reference) {
                if ($reference['coordinate'] !== null) {
                    $coordinates[] = $reference['coordinate'];
                }
            }
        }

        return [
            'directoryTags' => $tags,
            'directoryCoordinates' => $coordinates,
            'directoryIdentifier' => BookshelfDirectoryService::IDENTIFIER,
        ];
    }
}
