<?php

declare(strict_types=1);

namespace App\Controller\Bookshelf;

use App\Bookshelf\BookshelfDirectoryBooks;
use DecentNewsroom\BookshelfBundle\Navigation\BookshelfNavigationTrait;
use DecentNewsroom\BookshelfBundle\Service\Bookshelf\BookshelfDirectoryService;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class MyBooksController extends AbstractController
{
    use BookshelfNavigationTrait;

    #[Route('/bookshelf/my-books', name: 'bookshelf_my_books_local_fallback', methods: ['GET'], priority: 10)]
    #[IsGranted('ROLE_USER')]
    public function index(
        Request $request,
        BookshelfDirectoryService $directoryService,
        BookshelfDirectoryBooks $directoryBooks,
    ): Response {
        $user = $this->getUser();
        \assert($user !== null);

        $requestedPage = filter_var($request->query->all()['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($requestedPage === false) {
            throw new BadRequestHttpException('Invalid My Books page.');
        }

        $pubkey = $this->pubkeyFromIdentifier((string) $user->getUserIdentifier());
        $directoryTags = $directoryService->getEditableTagsForUser($pubkey);
        $references = $directoryService->extractBookReferences($directoryTags);
        $pageSize = BookshelfDirectoryBooks::PAGE_SIZE;
        $lastPage = max(1, intdiv(count($references) + $pageSize - 1, $pageSize));
        $page = min($requestedPage, $lastPage);
        $result = $directoryBooks->resolve(array_slice($references, ($page - 1) * $pageSize, $pageSize));

        return $this->render('@Bookshelf/bookshelf/my_books.html.twig', [
            'bookshelfNav' => $this->buildBookshelfNav(true),
            'books' => $result['books'],
            'available' => $result['available'],
            'referenceCount' => count($references),
            'missingBookCount' => $result['missing'],
            'page' => $page,
            'hasPrevious' => $page > 1,
            'hasNext' => $page < $lastPage,
            'directoryTags' => $directoryTags,
            'directoryIdentifier' => BookshelfDirectoryService::IDENTIFIER,
        ], new Response(headers: ['Cache-Control' => 'private, no-store']));
    }

    private function pubkeyFromIdentifier(string $npub): string
    {
        $npub = strtolower(trim($npub));
        if (str_starts_with($npub, 'nostr:')) {
            $npub = substr($npub, 6);
        }

        return PublicKey::fromBech32($npub)?->toHex()
            ?? throw new \InvalidArgumentException('Not a valid npub');
    }
}
