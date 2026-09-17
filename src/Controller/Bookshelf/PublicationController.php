<?php

declare(strict_types=1);

namespace App\Controller\Bookshelf;

use App\Service\Magazine\MagazineStructureService;
use App\Service\Magazine\PublicationIndexClassifier;
use DecentNewsroom\BookshelfBundle\Navigation\BookshelfNavigationTrait;
use DecentNewsroom\BookshelfBundle\Service\Bookshelf\BookshelfDirectoryService;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PublicationController extends AbstractController
{
    use BookshelfNavigationTrait;

    #[Route('/bookshelf/book/{book}', name: 'bookshelf_book', methods: ['GET'])]
    public function show(
        string $book,
        MagazineStructureService $magazineStructure,
        PublicationIndexClassifier $publicationIndexClassifier,
        BookshelfDirectoryService $directoryService,
    ): Response {
        $publication = $magazineStructure->findLatestIndexBySlug($book);
        if ($publication === null) {
            throw $this->createNotFoundException('Book not found');
        }

        if (!$publicationIndexClassifier->isBook($publication->getTags())) {
            return $this->redirectToRoute('magazine-index', ['mag' => $book]);
        }

        $structure = $magazineStructure->parseStructure($publication);
        $chapters = $magazineStructure->resolveChapters(
            $structure->chapterCoordinates,
            $structure->chapterRelayHints,
        );

        return $this->render('bookshelf/publication.html.twig', [
            'bookshelfNav' => $this->buildBookshelfNav($this->getUser() !== null),
            ...$this->directoryContext($directoryService),
            'publication' => $publication,
            'book' => $book,
            'publicationType' => $this->firstTagValue($publication->getTags(), 'type') ?? 'book',
            'metadata' => $this->metadataTags($publication->getTags()),
            'chapters' => $chapters,
            'rawEvent' => [
                'id' => $publication->getId(),
                'pubkey' => $publication->getPubkey(),
                'created_at' => $publication->getCreatedAt(),
                'kind' => $publication->getKind(),
                'tags' => $publication->getTags(),
                'content' => $publication->getContent(),
                'sig' => $publication->getSig(),
            ],
        ]);
    }

    /**
     * @param array<int, mixed> $tags
     * @return array<int, array{label: string, value: string, url: ?string}>
     */
    private function metadataTags(array $tags): array
    {
        $metadata = [];
        $structuralTags = ['d', 'a', 'e', 'E', 'p', 'P'];

        foreach ($tags as $tag) {
            if (!is_array($tag) || !isset($tag[0], $tag[1]) || !is_string($tag[0])) {
                continue;
            }

            $name = $tag[0];
            if (in_array($name, $structuralTags, true) || !is_scalar($tag[1])) {
                continue;
            }

            $value = (string) $tag[1];
            if ($value === '') {
                continue;
            }

            $label = match ($name) {
                'i' => strtoupper(strtok($value, ':') ?: 'Identifier'),
                't' => 'Topic',
                'doi' => 'DOI',
                'isbn', 'issbn' => strtoupper($name),
                default => ucfirst(str_replace('_', ' ', $name)),
            };

            if (count($tag) > 2) {
                $extraValues = array_slice($tag, 2);
                $extraValues = array_filter($extraValues, static fn (mixed $extra): bool => is_scalar($extra) && (string) $extra !== '');
                if ($extraValues !== []) {
                    $value .= ' (' . implode(', ', array_map(static fn (mixed $extra): string => (string) $extra, $extraValues)) . ')';
                }
            }

            if ($name === 'i' && str_contains($value, ':')) {
                $value = substr($value, strpos($value, ':') + 1);
            }

            $metadata[] = [
                'label' => $label,
                'value' => $value,
                'url' => preg_match('#^https?://#i', $value) === 1 ? $value : null,
            ];
        }

        return $metadata;
    }

    /**
     * @param array<int, mixed> $tags
     */
    private function firstTagValue(array $tags, string $name): ?string
    {
        foreach ($tags as $tag) {
            if (is_array($tag) && ($tag[0] ?? null) === $name && is_scalar($tag[1] ?? null)) {
                $value = (string) $tag[1];
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return null;
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
            $npub = strtolower(trim((string) $user->getUserIdentifier()));
            if (str_starts_with($npub, 'nostr:')) {
                $npub = substr($npub, 6);
            }

            $pubkey = PublicKey::fromBech32($npub)?->toHex()
                ?? throw new \InvalidArgumentException('Not a valid npub');
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
