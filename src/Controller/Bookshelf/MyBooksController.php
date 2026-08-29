<?php

declare(strict_types=1);

namespace App\Controller\Bookshelf;

use App\Bookshelf\BookshelfBookLoader;
use App\Bookshelf\BookshelfDirectoryRefreshService;
use DecentNewsroom\BookshelfBundle\Navigation\BookshelfNavigationTrait;
use DecentNewsroom\BookshelfBundle\Service\Bookshelf\BookshelfDirectoryService;
use DecentNewsroom\BookshelfBundle\Service\Mercury\MercuryApiException;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class MyBooksController extends AbstractController
{
    use BookshelfNavigationTrait;

    #[Route('/bookshelf/my-books', name: 'bookshelf_my_books_local_fallback', methods: ['GET'], priority: 10)]
    #[IsGranted('ROLE_USER')]
    public function index(
        BookshelfDirectoryRefreshService $directoryRefreshService,
        BookshelfDirectoryService $directoryService,
        BookshelfBookLoader $bookLoader,
    ): Response {
        $user = $this->getUser();
        \assert($user !== null);

        $pubkey = $this->pubkeyFromIdentifier((string) $user->getUserIdentifier());
        $directoryRefreshService->refreshForUser($pubkey);

        $directoryTags = $directoryService->getEditableTagsForUser($pubkey);
        $references = $directoryService->extractBookReferences($directoryTags);
        $available = true;

        try {
            $books = $bookLoader->getBooksForReferences($references);
        } catch (MercuryApiException) {
            $books = [];
            $available = false;
        }

        return $this->render('@Bookshelf/bookshelf/my_books.html.twig', [
            'bookshelfNav' => $this->buildBookshelfNav(true),
            'books' => $books,
            'available' => $available,
            'referenceCount' => count($references),
            'missingBookCount' => max(0, count($references) - count($books)),
            'directoryTags' => $directoryTags,
            'directoryIdentifier' => BookshelfDirectoryService::IDENTIFIER,
        ]);
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
