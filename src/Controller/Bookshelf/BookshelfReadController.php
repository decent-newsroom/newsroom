<?php

declare(strict_types=1);

namespace App\Controller\Bookshelf;

use App\Bookshelf\BookshelfRelayBookLoader;
use App\Bookshelf\BookshelfReaderContents;
use App\Bookshelf\BookshelfReaderTraversalSigner;
use App\Service\UserMuteListService;
use AsciiDocConverter;
use DecentNewsroom\BookshelfBundle\Navigation\BookshelfNavigationTrait;
use DecentNewsroom\BookshelfBundle\Service\Bookshelf\BookshelfDirectoryService;
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
        BookshelfRelayBookLoader $relayBookLoader,
        BookshelfReaderContents $readerContents,
        BookshelfDirectoryService $directoryService,
        LoggerInterface $logger,
        UserMuteListService $muteList,
    ): Response {
        $query = trim((string) $request->query->get('q', ''));
        $directoryContext = $this->directoryContext($directoryService);
        $source = $readerContents->source(strtolower($id));
        if ($source !== null && $readerContents->indexAtPath($source, [], $this->mutedPubkeys($muteList)) === null) {
            throw $this->createNotFoundException();
        }
        $book = $source === null ? null : $relayBookLoader->bookFromEvent($source, loadChapters: false);
        if ($source === null) {
            $logger->warning('Bookshelf index unavailable; reader can be retried.', ['event_id' => $id]);
        }

        return $this->render($book !== null ? 'bookshelf/read_nested.html.twig' : 'bookshelf/read_unavailable.html.twig', [
            'bookshelfNav' => $this->buildBookshelfNav($this->getUser() !== null),
            ...$directoryContext,
            'book' => $book,
            'available' => $source !== null,
            'query' => $query,
            'readerId' => strtolower($id),
        ], new Response(
            status: $source === null ? Response::HTTP_SERVICE_UNAVAILABLE : Response::HTTP_OK,
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
        BookshelfReaderTraversalSigner $signer,
    ): Response {
        $rawPath = $request->query->get('path', '');
        $rawPage = $request->query->get('page', '1');
        if (($rawPath !== '' && !preg_match('/^(?:0|[1-9][0-9]{0,8})(?:\.(?:0|[1-9][0-9]{0,8})){0,4}$/D', $rawPath))
            || !preg_match('/^(?:[1-9][0-9]?|100)$/D', $rawPage)) {
            throw $this->createNotFoundException();
        }
        $path = $rawPath === '' ? [] : array_map(intval(...), explode('.', $rawPath));
        $page = (int) $rawPage;
        $id = strtolower($id);
        $rawRevisions = $request->query->get('revisions', '');
        $signature = $request->query->get('signature', '');
        if (!is_string($rawRevisions) || !is_string($signature)
            || strlen($rawRevisions) > 5 * 65) {
            throw $this->createNotFoundException();
        }
        $revisions = $rawRevisions === '' ? [] : explode(',', $rawRevisions);
        foreach ($revisions as $revision) {
            if (preg_match('/^[0-9a-f]{64}$/D', $revision) !== 1) {
                throw $this->createNotFoundException();
            }
        }
        if ($path !== []) {
            $boundCount = count($revisions);
            if (($boundCount !== count($path) && !($page === 1 && $boundCount === count($path) - 1))
                || !$signer->isValid($id, $path, $revisions, $signature)) {
                throw $this->createNotFoundException();
            }
        }
        if ($path === [] && ($revisions !== [] || $signature !== '')) {
            throw $this->createNotFoundException();
        }
        $muted = $this->mutedPubkeys($muteList);
        $root = $readerContents->source($id);
        $resolvedRevisions = [];
        $source = $root === null ? null : $readerContents->indexAtPath($root, $path, $muted, $revisions, $resolvedRevisions);
        $contents = $source === null ? null : $readerContents->childrenPage($source, $muted, $page);
        $childSignatures = [];
        $body = null;
        $indexMetadata = null;
        if ($contents !== null) {
            $this->renderEntries($contents['entries'], $converter, $logger);
            foreach ($contents['entries'] as $entry) {
                if ($entry['kind'] === 30040 && !$entry['invalid'] && count($path) < 5) {
                    $childSignatures[$entry['position']] = $signer->sign($id, [...$path, $entry['position']], $resolvedRevisions);
                }
            }
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
            'revisions' => $resolvedRevisions,
            'childSignatures' => $childSignatures,
            'pageSignature' => $path === [] ? null : $signer->sign($id, $path, $resolvedRevisions),
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
