<?php

declare(strict_types=1);

namespace App\Controller\Bookshelf;

use App\Bookshelf\BookshelfRelayBookLoader;
use AsciiDocConverter;
use DecentNewsroom\BookshelfBundle\Navigation\BookshelfNavigationTrait;
use DecentNewsroom\BookshelfBundle\Service\Bookshelf\BookshelfDirectoryService;
use DecentNewsroom\BookshelfBundle\Service\Mercury\MercuryApiException;
use DecentNewsroom\BookshelfBundle\Service\Mercury\MercuryBookService;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class BookshelfReadController extends AbstractController
{
    use BookshelfNavigationTrait;

    #[Route('/bookshelf/{id}', name: 'bookshelf_read_native_fallback', requirements: ['id' => '[a-fA-F0-9]{64}'], methods: ['GET'], priority: 10)]
    public function read(
        string $id,
        Request $request,
        MercuryBookService $bookService,
        BookshelfRelayBookLoader $relayBookLoader,
        BookshelfDirectoryService $directoryService,
        AsciiDocConverter $asciiDocConverter,
        LoggerInterface $logger,
    ): Response {
        $query = trim((string) $request->query->get('q', ''));
        $directoryContext = $this->directoryContext($directoryService);
        $available = true;

        try {
            $book = $bookService->getBook(strtolower($id));
        } catch (MercuryApiException $exception) {
            $logger->warning('Mercury bookshelf request failed; trying direct Nostr lookup.', [
                'event_id' => $id,
                'error' => $exception->getMessage(),
            ]);
            $book = null;
            $available = false;
        }

        $book ??= $relayBookLoader->getBook(strtolower($id));
        if ($book !== null) {
            $book = $relayBookLoader->fillMissingChapters($book);
            $available = true;
            $this->renderChapters($book, $asciiDocConverter, $logger);
        }

        return $this->render('@Bookshelf/bookshelf/read.html.twig', [
            'bookshelfNav' => $this->buildBookshelfNav($this->getUser() !== null),
            ...$directoryContext,
            'book' => $book,
            'available' => $available,
            'query' => $query,
        ], new Response(status: $book === null && !$available ? Response::HTTP_SERVICE_UNAVAILABLE : Response::HTTP_OK));
    }

    /** @param array<string, mixed> $book */
    private function renderChapters(array &$book, AsciiDocConverter $asciiDocConverter, LoggerInterface $logger): void
    {
        foreach ($book['chapters'] as &$chapter) {
            if (!is_array($chapter) || !($chapter['available'] ?? false)) {
                $chapter['html'] = null;
                continue;
            }

            try {
                $chapter['html'] = $asciiDocConverter->convert((string) $chapter['content']);
            } catch (\Throwable $exception) {
                $logger->warning('Bookshelf chapter conversion failed.', [
                    'event_id' => $chapter['id'] ?? null,
                    'error' => $exception->getMessage(),
                ]);
                $chapter['html'] = '<pre>' . htmlspecialchars((string) $chapter['content'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</pre>';
            }
        }
        unset($chapter);
    }

    /** @return array{directoryTags: array<int, array<int, string>>, directoryCoordinates: string[], directoryIdentifier: string} */
    private function directoryContext(BookshelfDirectoryService $directoryService): array
    {
        $tags = [];
        $coordinates = [];
        $user = $this->getUser();
        if ($user !== null) {
            $npub = strtolower(trim((string) $user->getUserIdentifier()));
            if (str_starts_with($npub, 'nostr:')) {
                $npub = substr($npub, 6);
            }
            $pubkey = PublicKey::fromBech32($npub)?->toHex() ?? throw new \InvalidArgumentException('Not a valid npub');
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
