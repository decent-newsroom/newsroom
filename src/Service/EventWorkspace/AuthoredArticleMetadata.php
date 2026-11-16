<?php

declare(strict_types=1);

namespace App\Service\EventWorkspace;

use App\Dto\EventWorkspace\EventPresentation;
use App\Dto\EventWorkspace\EventReferenceType;
use App\Entity\Article;
use App\Enum\KindsEnum;
use App\Repository\DeletedEventRepository;
use App\Repository\EventRepository;
use App\Repository\HiddenCoordinateRepository;
use App\Service\Nostr\NostrNip19Service;
use App\Service\Reader\ContentAuthorAccessPolicy;

final readonly class AuthoredArticleMetadata
{
    public function __construct(
        private AuthoredArticlePreview $previews,
        private EventRepository $events,
        private ContentAuthorAccessPolicy $authors,
        private DeletedEventRepository $deletions,
        private HiddenCoordinateRepository $hidden,
        private NostrNip19Service $nip19,
    ) {}

    /**
     * Only enrich the already-paginated owner's articles. No target resolution,
     * profile fetching, body rendering, or event persistence occurs here.
     *
     * @param list<Article> $articles
     * @return array<string, array{
     *     sensitive: bool, hashtags: list<string>, mentions: list<string>,
     *     sources: list<string>, language: ?string, client: ?string
     * }>
     */
    public function forArticles(array $articles, string $owner): array
    {
        $candidates = [];
        foreach ($articles as $article) {
            $id = $article->getEventId();
            if ($article->getPubkey() === $owner
                && in_array($article->getKind(), [KindsEnum::LONGFORM, KindsEnum::LONGFORM_DRAFT], true)
                && $id !== null && preg_match('/^[0-9a-f]{64}$/D', $id) === 1) {
                $candidates[$id] = $article;
            }
        }
        if ($candidates === [] || $this->authors->isSuppressed($owner)) {
            return [];
        }

        $originals = [];
        foreach ($this->events->findBy([
            'id' => array_keys($candidates), 'pubkey' => $owner,
            'kind' => [KindsEnum::LONGFORM->value, KindsEnum::LONGFORM_DRAFT->value],
        ]) as $event) {
            $originals[$event->getId()] = $event;
        }
        $presentations = [];
        foreach ($candidates as $id => $article) {
            $presentation = $this->previews->fromAuthorizedArticle($article, $originals[$id] ?? null);
            if ($presentation !== null) {
                $presentations[$id] = $presentation;
            }
        }
        if ($presentations === []) {
            return [];
        }
        $hidden = array_fill_keys($this->hidden->findHiddenCoordinates(array_values(array_filter(
            array_map(static fn (EventPresentation $source): ?string => $source->coordinate, $presentations),
        ))), true);
        $deleted = array_fill_keys($this->deletions->findSuppressedEventIds(array_values($originals)), true);

        $metadata = [];
        foreach ($presentations as $id => $source) {
            if (isset($hidden[$source->coordinate]) || isset($deleted[$id])
                || (!isset($originals[$id]) && $this->deletions->isSuppressed(
                $id, $source->kind, $source->authorPubkey, $candidates[$id]->getSlug(), $source->createdAt,
            ))) {
                continue;
            }
            $metadata[$id] = $this->fromPresentation($source);
        }

        return $metadata;
    }

    /**
     * Compact display facets, not a replacement for the ordered raw references.
     *
     * @return array{
     *     sensitive: bool, hashtags: list<string>, mentions: list<string>,
     *     sources: list<string>, language: ?string, client: ?string
     * }
     */
    private function fromPresentation(EventPresentation $source): array
    {
        $metadata = [
            'sensitive' => $source->isSensitive,
            'hashtags' => [], 'mentions' => [], 'sources' => [],
            'language' => null, 'client' => null,
        ];
        if ($source->isSensitive) {
            return $metadata;
        }
        foreach ($source->getRawTags() as $tag) {
            if (!is_array($tag) || !is_string($tag[1] ?? null) || ($value = trim($tag[1])) === '') {
                continue;
            }
            switch ($tag[0] ?? null) {
                case 't':
                    if (!in_array($value, $metadata['hashtags'], true)) {
                        $metadata['hashtags'][] = $value;
                    }
                    break;
                case 'r':
                    if (filter_var($value, FILTER_VALIDATE_URL) !== false
                        && in_array(strtolower((string) parse_url($value, PHP_URL_SCHEME)), ['http', 'https'], true)
                        && !in_array($value, $metadata['sources'], true)) {
                        $metadata['sources'][] = $value;
                    }
                    break;
                case 'lang':
                    $metadata['language'] ??= $value;
                    break;
                case 'client':
                    $metadata['client'] ??= $value;
                    break;
            }
        }
        foreach ($source->references as $reference) {
            if ($reference->type !== EventReferenceType::Profile || $reference->target === null) {
                continue;
            }
            $npub = $this->nip19->encodeNpub($reference->target);
            if (!in_array($npub, $metadata['mentions'], true)) {
                $metadata['mentions'][] = $npub;
            }
        }

        return $metadata;
    }
}
