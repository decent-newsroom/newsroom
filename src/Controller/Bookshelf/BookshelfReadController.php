<?php

declare(strict_types=1);

namespace App\Controller\Bookshelf;

use App\Bookshelf\BookshelfRelayBookLoader;
use App\Bookshelf\BookshelfReaderContents;
use App\Bookshelf\BookshelfReaderTraversalSigner;
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
        BookshelfReaderTraversalSigner $signer,
    ): Response {
        $query = trim((string) $request->query->get('q', ''));
        $directoryContext = $this->directoryContext($directoryService);
        $source = $readerContents->source(strtolower($id));
        if ($source !== null && $readerContents->indexAtPath($source, []) === null) {
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
            ...$this->outlineParameters(strtolower($id), [], [], $source, $readerContents, $signer),
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
        BookshelfReaderTraversalSigner $signer,
    ): Response {
        [$source, $path, $revisions] = $this->requestSource($id, $request, $readerContents, $signer);

        return $this->render('bookshelf/_reader_contents.html.twig',
            $this->outlineParameters(strtolower($id), $path, $revisions, $source, $readerContents, $signer),
            new Response(headers: ['Cache-Control' => 'private, no-store']),
        );
    }

    #[Route('/bookshelf/{id}/chapter/{position}', name: 'bookshelf_reader_chapter', requirements: ['id' => '[a-fA-F0-9]{64}', 'position' => '0|[1-9][0-9]{0,8}'], methods: ['GET'])]
    #[Route('/bookshelf/{id}/index-body', name: 'bookshelf_reader_index_body', requirements: ['id' => '[a-fA-F0-9]{64}'], defaults: ['position' => -1], methods: ['GET'])]
    public function chapter(
        string $id,
        int $position,
        Request $request,
        BookshelfReaderContents $readerContents,
        BookshelfReaderTraversalSigner $signer,
        AsciiDocConverter $converter,
        LoggerInterface $logger,
    ): Response {
        [$source, $path] = $this->requestSource($id, $request, $readerContents, $signer, $position);
        $chapter = null;
        if ($source !== null) {
            $chapter = $position === -1
                ? ['id' => $source->id, 'available' => true, 'content' => (string) ($source->content ?? '')]
                : $readerContents->chapter($source, $position);
        }
        if ($chapter !== null) {
            try {
                $chapter['html'] = $readerContents->renderedBody(
                    $chapter['id'], $chapter['content'], fn (): string => $converter->convert($chapter['content']),
                );
            } catch (\Throwable $exception) {
                $logger->warning('Bookshelf chapter conversion failed.', [
                    'event_id' => $chapter['id'],
                    'error' => $exception->getMessage(),
                ]);
                $chapter['html'] = '<pre>' . htmlspecialchars($chapter['content'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</pre>';
            }
        }

        return $this->render('bookshelf/_reader_chapter.html.twig', [
            'rootId' => strtolower($id), 'path' => $path, 'position' => $position, 'chapter' => $chapter,
        ], new Response(headers: ['Cache-Control' => 'private, no-store']));
    }

    /** @return array{?object, list<int>, list<string>} */
    private function requestSource(
        string $id,
        Request $request,
        BookshelfReaderContents $readerContents,
        BookshelfReaderTraversalSigner $signer,
        ?int $chapterPosition = null,
    ): array {
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
        if ($chapterPosition !== null) {
            if (count($revisions) !== count($path)
                || !$signer->isValidChapter($id, $path, $revisions, $chapterPosition, $signature)) {
                throw $this->createNotFoundException();
            }
        } elseif ($path !== []) {
            $boundCount = count($revisions);
            if (($boundCount !== count($path) && !($page === 1 && $boundCount === count($path) - 1))
                || !$signer->isValid($id, $path, $revisions, $signature)) {
                throw $this->createNotFoundException();
            }
        }
        if ($chapterPosition === null && $path === [] && ($revisions !== [] || $signature !== '')) {
            throw $this->createNotFoundException();
        }
        $root = $readerContents->source($id);
        $resolvedRevisions = [];
        $source = $root === null ? null : $readerContents->indexAtPath($root, $path, $revisions, $resolvedRevisions);
        return [$source, $path, $resolvedRevisions];
    }

    /** @param list<int> $path @param list<string> $revisions @return array<string, mixed> */
    private function outlineParameters(
        string $id,
        array $path,
        array $revisions,
        ?object $source,
        BookshelfReaderContents $readerContents,
        BookshelfReaderTraversalSigner $signer,
    ): array {
        $contents = $source === null ? null : $readerContents->outline($source);
        $childSignatures = [];
        $chapterSignatures = [];
        if ($contents !== null) {
            foreach ($contents['entries'] as $entry) {
                if ($entry['kind'] === 30040 && !$entry['invalid'] && count($path) < 5) {
                    $childSignatures[$entry['position']] = $signer->sign($id, [...$path, $entry['position']], $revisions);
                } elseif ($entry['kind'] === 30041 && !$entry['invalid']) {
                    $chapterSignatures[$entry['position']] = $signer->signChapter($id, $path, $revisions, $entry['position']);
                }
            }
        }

        return [
            'rootId' => $id, 'path' => $path, 'page' => 1,
            'contents' => $contents,
            'indexMetadata' => $path === [] || $source === null ? null : $readerContents->indexMetadata($source),
            'revisions' => $revisions,
            'childSignatures' => $childSignatures,
            'chapterSignatures' => $chapterSignatures,
            'bodySignature' => $path !== [] && ($source->content ?? '') !== ''
                ? $signer->signChapter($id, $path, $revisions, -1) : null,
        ];
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
