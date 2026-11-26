<?php

declare(strict_types=1);

namespace App\Controller\Bookshelf;

use App\Bookshelf\BookshelfRelayBookLoader;
use App\Bookshelf\BookshelfReaderContents;
use App\Service\UserMuteListService;
use AsciiDocConverter;
use DecentNewsroom\BookshelfBundle\Navigation\BookshelfNavigationTrait;
use DecentNewsroom\BookshelfBundle\Service\Bookshelf\BookshelfDirectoryService;
use DecentNewsroom\BookshelfBundle\Service\Mercury\Exception\MercuryApiException;
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
        BookshelfReaderContents $readerContents,
        BookshelfDirectoryService $directoryService,
        AsciiDocConverter $asciiDocConverter,
        LoggerInterface $logger,
        UserMuteListService $muteList,
    ): Response {
        $query = trim((string) $request->query->get('q', ''));
        $directoryContext = $this->directoryContext($directoryService);
        $available = true;

        $source = $readerContents->source(strtolower($id));
        $nested = $source !== null && $readerContents->hasNestedIndexes($source);
        if ($nested) {
            $book = $relayBookLoader->bookFromEvent($source, loadChapters: false);
        } else {
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
            if ($book === null && $source !== null) {
                $book = $relayBookLoader->bookFromEvent($source);
            }
        }
        $book ??= $relayBookLoader->getBook(strtolower($id));
        if ($book !== null) {
            if ($source === null) {
                $logger->warning('Bookshelf index unavailable; nested contents could not be checked.', ['event_id' => $id]);
            }
            if (!$nested) {
                $book = $relayBookLoader->fillMissingChapters($book);
            }
            if ($source !== null) {
                $muted = $this->mutedPubkeys($muteList);
                if ($nested) {
                    if ($readerContents->indexAtPath($source, [], $muted) === null) {
                        throw $this->createNotFoundException();
                    }
                    $contents = $readerContents->childrenPage($source, $muted, 1);
                    $this->renderEntries($contents['entries'], $asciiDocConverter, $logger);
                } else {
                    $book = $readerContents->expand($book, $source, $muted);
                }
            }
            $available = true;
            if (!$nested) {
                $this->renderChapters($book, $asciiDocConverter, $logger);
            }
        }

        return $this->render($nested && $book !== null ? 'bookshelf/read_nested.html.twig' : '@Bookshelf/bookshelf/read.html.twig', [
            'bookshelfNav' => $this->buildBookshelfNav($this->getUser() !== null),
            ...$directoryContext,
            'book' => $book,
            'available' => $available,
            'query' => $query,
            'contents' => $contents ?? null,
        ], new Response(
            status: $book === null && !$available ? Response::HTTP_SERVICE_UNAVAILABLE : Response::HTTP_OK,
            headers: ['Cache-Control' => 'private, no-store'],
        ));
    }

    #[Route('/bookshelf/{id}/contents', name: 'bookshelf_reader_contents', requirements: ['id' => '[a-fA-F0-9]{64}'], methods: ['GET'])]
    public function contents(
        string $id,
        Request $request,
        BookshelfReaderContents $readerContents,
        AsciiDocConverter $converter,
        LoggerInterface $logger,
        UserMuteListService $muteList,
    ): Response {
        $rawPath = $request->query->get('path', '');
        $rawPage = $request->query->get('page', '1');
        if (($rawPath !== '' && !preg_match('/^(?:0|[1-9][0-9]{0,8})(?:\.(?:0|[1-9][0-9]{0,8})){0,4}$/D', $rawPath))
            || !preg_match('/^(?:[1-9]|10)$/D', $rawPage)) {
            throw $this->createNotFoundException();
        }
        $path = $rawPath === '' ? [] : array_map(intval(...), explode('.', $rawPath));
        $page = (int) $rawPage;
        $id = strtolower($id);
        $muted = $this->mutedPubkeys($muteList);
        $root = $readerContents->source($id);
        $source = $root === null ? null : $readerContents->indexAtPath($root, $path, $muted);
        $contents = $source === null ? null : $readerContents->childrenPage($source, $muted, $page);
        $body = null;
        $indexMetadata = null;
        if ($contents !== null) {
            $this->renderEntries($contents['entries'], $converter, $logger);
            if ($path !== [] && $page === 1) {
                $indexMetadata = $readerContents->indexMetadata($source);
                if (($source->content ?? '') !== '') {
                    $entry = [['available' => true, 'content' => $source->content, 'id' => $source->id]];
                    $this->renderEntries($entry, $converter, $logger);
                    $body = $entry[0]['html'];
                }
            }
        }

        return $this->render('bookshelf/_reader_contents.html.twig', [
            'rootId' => $id, 'path' => $path, 'page' => $page,
            'contents' => $contents, 'indexBody' => $body, 'indexMetadata' => $indexMetadata,
        ], new Response(headers: ['Cache-Control' => 'private, no-store']));
    }

    /** @return list<string> */
    private function mutedPubkeys(UserMuteListService $muteList): array
    {
        if ($this->getUser() === null) {
            return [];
        }
        $pubkey = PublicKey::fromBech32($this->getUser()->getUserIdentifier())?->toHex()
            ?? throw $this->createAccessDeniedException();
        return $muteList->getMutedPubkeysStrict($pubkey);
    }

    /** @param list<array<string, mixed>> $entries */
    private function renderEntries(array &$entries, AsciiDocConverter $converter, LoggerInterface $logger): void
    {
        $book = ['chapters' => &$entries];
        $this->renderChapters($book, $converter, $logger);
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
