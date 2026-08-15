<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Entity\Article;
use App\Enum\KindsEnum;
use App\Message\FetchEventFromRelaysMessage;
use App\Message\PrefetchNostrEmbedsMessage;
use App\Repository\ArticleRepository;
use App\Service\Cache\RedisCacheService;
use App\Service\EmbedReferenceExtractor;
use App\Service\HighlightService;
use App\Service\Nostr\NostrEventParser;
use App\Service\Nostr\NostrIdentityService;
use App\Util\CommonMark\Converter;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\User\UserInterface;

final readonly class ArticlePageLoader
{
    public function __construct(
        private ArticleRepository $articleRepository,
        private RedisCacheService $redisCacheService,
        private Converter $converter,
        private LoggerInterface $logger,
        private NostrEventParser $eventParser,
        private EmbedReferenceExtractor $embedExtractor,
        private MessageBusInterface $bus,
        private NostrIdentityService $identityService,
        private UrlGeneratorInterface $urlGenerator,
        private ArticleAccessService $articleAccess,
        private HighlightService $highlightService,
    ) {
    }

    public function loadPublicArticle(string $npub, string $slug, ?UserInterface $viewer): ArticlePageResult
    {
        $slug = urldecode($slug);
        $pubkey = $this->resolvePubkey($npub, 'Invalid author identifier.');

        /** @var Article|null $article */
        $article = $this->articleRepository->findOneBy([
            'slug' => $slug,
            'pubkey' => $pubkey,
        ], ['createdAt' => 'DESC']);

        if (!$article instanceof Article) {
            return $this->queueRelayFetch($npub, $pubkey, $slug);
        }

        if ($article->isEssayistExclusive() && !$this->articleAccess->canViewEssayistExclusive($viewer, $article)) {
            return ArticlePageResult::accessRequired();
        }

        $tags = $this->tagsFromRaw($article);
        $advancedMetadata = $tags !== null ? $this->eventParser->parseAdvancedMetadata($tags) : null;
        $htmlContent = $this->resolveHtmlContent($article, $tags);
        $author = $this->redisCacheService->getMetadata((string) $article->getPubkey())->toStdClass();
        $canonical = $this->urlGenerator->generate(
            'author-article-slug',
            ['npub' => $npub, 'slug' => $article->getSlug()],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $this->prefetchUnresolvedEmbeds($article, $htmlContent);

        return ArticlePageResult::ready(
            article: $article,
            author: $author,
            npub: $npub,
            content: $htmlContent,
            canEdit: $this->articleAccess->canEdit($viewer, $article),
            canonical: $canonical,
            advancedMetadata: $advancedMetadata,
        );
    }

    public function loadDraftArticle(string $npub, string $slug, ?UserInterface $viewer): ArticlePageResult
    {
        if (!$viewer instanceof UserInterface) {
            throw new AccessDeniedException('You must be logged in to view drafts.');
        }

        $slug = urldecode($slug);
        $pubkey = $this->resolvePubkey($npub, 'Invalid author identifier.');
        $viewerPubkey = $this->resolveViewerPubkey($viewer);

        if (!hash_equals($pubkey, $viewerPubkey)) {
            throw new AccessDeniedException('You can only view your own drafts.');
        }

        /** @var Article|null $draft */
        $draft = $this->articleRepository->findOneBy([
            'slug' => $slug,
            'pubkey' => $pubkey,
            'kind' => KindsEnum::LONGFORM_DRAFT,
        ], ['createdAt' => 'DESC']);

        if (!$draft instanceof Article) {
            return ArticlePageResult::notFound(
                'The draft could not be found. It may have been deleted or published.',
                $slug,
            );
        }

        $tags = $this->tagsFromRaw($draft);
        $advancedMetadata = $tags !== null ? $this->eventParser->parseAdvancedMetadata($tags) : null;
        $htmlContent = $this->resolveHtmlContent($draft, $tags, 'Draft');
        $author = $this->redisCacheService->getMetadata((string) $draft->getPubkey())->toStdClass();
        $canonical = $this->urlGenerator->generate(
            'author-draft-slug',
            ['npub' => $npub, 'slug' => $draft->getSlug()],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        return ArticlePageResult::ready(
            article: $draft,
            author: $author,
            npub: $npub,
            content: $htmlContent,
            canEdit: $this->articleAccess->canEdit($viewer, $draft),
            canonical: $canonical,
            advancedMetadata: $advancedMetadata,
            highlights: $this->loadDraftHighlights($draft),
            isDraft: true,
        );
    }

    private function queueRelayFetch(string $npub, string $pubkey, string $slug): ArticlePageResult
    {
        $lookupKey = 'article:' . md5($pubkey . ':' . $slug);
        $this->logger->info('Article not in DB, dispatching async relay fetch', [
            'npub' => $npub,
            'slug' => $slug,
            'lookupKey' => $lookupKey,
        ]);

        try {
            $this->bus->dispatch(new FetchEventFromRelaysMessage(
                lookupKey: $lookupKey,
                type: 'naddr',
                kind: KindsEnum::LONGFORM->value,
                pubkey: $pubkey,
                identifier: $slug,
            ));
        } catch (\Throwable $e) {
            $this->logger->warning('Could not dispatch async article fetch', ['error' => $e->getMessage()]);
        }

        return ArticlePageResult::loading(
            $lookupKey,
            $this->urlGenerator->generate('author-article-slug', ['npub' => $npub, 'slug' => $slug]),
        );
    }

    /**
     * @param array<int, mixed>|null $tags
     */
    private function resolveHtmlContent(Article $article, ?array $tags, string $label = 'Article'): string
    {
        $htmlContent = $article->getProcessedHtml();
        $this->logger->info($label . ' content retrieval', [
            'article_id' => $article->getId(),
            'slug' => $article->getSlug(),
            'pubkey' => $article->getPubkey(),
            'has_cached_html' => $htmlContent !== null,
        ]);

        if ($htmlContent !== null && $htmlContent !== '') {
            return $htmlContent;
        }

        try {
            $htmlContent = $this->converter->convertToHTML(
                (string) $article->getContent(),
                null,
                $article->getKind()?->value,
                $tags,
            );
            $article->setProcessedHtml($htmlContent);
        } catch (\Throwable $e) {
            $this->logger->error('Error converting ' . strtolower($label) . ' content to HTML', [
                'article_id' => $article->getId(),
                'error' => $e->getMessage(),
            ]);
            $htmlContent = '';
        }

        return $htmlContent;
    }

    /**
     * @return array<int, mixed>
     */
    private function loadDraftHighlights(Article $draft): array
    {
        try {
            $draftCoordinate = KindsEnum::LONGFORM_DRAFT->value . ':' . $draft->getPubkey() . ':' . $draft->getSlug();

            return $this->highlightService->getHighlightsForArticle($draftCoordinate);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return array<int, mixed>|null
     */
    private function tagsFromRaw(Article $article): ?array
    {
        $raw = $article->getRaw();
        if (!is_array($raw)) {
            return null;
        }

        $tags = $raw['tags'] ?? null;

        return is_array($tags) ? $tags : null;
    }

    private function resolvePubkey(string $identifier, string $message): string
    {
        try {
            return strtolower($this->identityService->toHex($identifier));
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException($message, 0, $e);
        }
    }

    private function resolveViewerPubkey(UserInterface $viewer): string
    {
        try {
            return strtolower($this->identityService->toHex($viewer->getUserIdentifier()));
        } catch (\Throwable) {
            throw new AccessDeniedException('Invalid user identifier.');
        }
    }

    private function prefetchUnresolvedEmbeds(Article $article, string $htmlContent): void
    {
        try {
            $refs = $this->embedExtractor->extractFromHtml($htmlContent);
            if (empty($refs['eventIds']) && empty($refs['coordinates'])) {
                return;
            }

            $articleCoordinate = '30023:' . $article->getPubkey() . ':' . $article->getSlug();
            $this->bus->dispatch(new PrefetchNostrEmbedsMessage(
                $articleCoordinate,
                $refs['eventIds'],
                $refs['coordinates'],
                $refs['relayHints'],
            ));
            $this->logger->info('Dispatched embed prefetch for article', [
                'coordinate' => $articleCoordinate,
                'event_ids' => count($refs['eventIds']),
                'coordinates' => count($refs['coordinates']),
            ]);
        } catch (\Throwable $e) {
            $this->logger->debug('Could not dispatch embed prefetch', ['error' => $e->getMessage()]);
        }
    }
}