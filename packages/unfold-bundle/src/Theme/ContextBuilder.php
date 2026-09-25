<?php

namespace DecentNewsroom\UnfoldBundle\Theme;

use DecentNewsroom\UnfoldBundle\Config\SiteConfig;
use DecentNewsroom\UnfoldBundle\Content\CategoryData;
use DecentNewsroom\UnfoldBundle\Content\PostData;
use DecentNewsroom\UnfoldBundle\Contract\Comment;
use DecentNewsroom\UnfoldBundle\Contract\CommentProviderInterface;
use DecentNewsroom\UnfoldBundle\Contract\MarkdownConverterInterface;
use DecentNewsroom\UnfoldBundle\Contract\ProfileMetadataProviderInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Builds Ghost-compatible context for Handlebars templates
 */
class ContextBuilder
{
    private const CACHE_TTL = 86400; // 24 hours - content is fixed per event

    public function __construct(
        private readonly MarkdownConverterInterface $converter,
        private readonly CacheItemPoolInterface $cache,
        private readonly ProfileMetadataProviderInterface $profileMetadata,
        private readonly CommentProviderInterface $comments,
        private readonly ?TranslatorInterface $translator = null,
        private readonly string $platformBaseUrl = 'https://decentnewsroom.com',
    ) {}

    /**
     * Build context for home page
     *
     * @param CategoryData[] $categories
     * @param PostData[] $posts
     */
    public function buildHomeContext(SiteConfig $site, array $categories, array $posts): array
    {
        $siteContext = $this->buildSiteContext($site, $categories, '/');
        return [
            '@site' => $siteContext,
            'site' => $siteContext,  // Also provide without @ for LightnCandy compatibility
            '@custom' => $this->buildCustomContext(),
            '@pageType' => 'home',
            ...$this->buildFooterContext($site),
            'posts' => array_map([$this, 'buildPostListItemContext'], $posts),
            'pagination' => $this->buildPaginationContext(count($posts)),
        ];
    }

    /**
     * Build context for category page
     *
     * @param CategoryData[] $categories
     * @param PostData[] $posts
     */
    public function buildCategoryContext(
        SiteConfig $site,
        array $categories,
        CategoryData $category,
        array $posts
    ): array {
        $siteContext = $this->buildSiteContext($site, $categories, '/' . $category->slug);
        return [
            '@site' => $siteContext,
            'site' => $siteContext,  // Also provide without @ for LightnCandy compatibility
            '@custom' => $this->buildCustomContext(),
            '@pageType' => 'tag',
            ...$this->buildFooterContext($site),
            'category' => [
                'slug' => $category->slug,
                'title' => $category->title,
                'summary' => $category->summary,
                'url' => '/' . $category->slug,
            ],
            'posts' => array_map([$this, 'buildPostListItemContext'], $posts),
            'pagination' => $this->buildPaginationContext(count($posts)),
        ];
    }

    /**
     * Build context for post page
     *
     * @param CategoryData[] $categories
     */
    public function buildPostContext(SiteConfig $site, array $categories, PostData $post, ?CategoryData $primaryCategory = null): array
    {
        $siteContext = $this->buildSiteContext($site, $categories, null);
        return [
            '@site' => $siteContext,
            'site' => $siteContext,  // Also provide without @ for LightnCandy compatibility
            '@custom' => $this->buildCustomContext(),
            '@pageType' => 'post',
            ...$this->buildFooterContext($site),
            'post' => $this->buildSinglePostContext($post, $primaryCategory),
        ];
    }

    /**
     * Build the publication About page, including two independently deduped people lists.
     *
     * @param CategoryData[] $categories
     * @param list<string> $featuredWriterPubkeys
     * @return array<string, mixed>
     */
    public function buildAboutContext(SiteConfig $site, array $categories, ?PostData $article, array $featuredWriterPubkeys): array
    {
        $siteContext = $this->buildSiteContext($site, $categories, '/about');
        $indexAuthors = $site->authorPubkeys;
        foreach ($categories as $category) {
            array_push($indexAuthors, ...$category->authorPubkeys);
        }

        $indexAuthors = $this->uniquePubkeys($indexAuthors);
        $featuredWriterPubkeys = $this->uniquePubkeys($featuredWriterPubkeys);
        $profiles = $this->buildPeopleProfiles(array_values(array_unique(array_merge($indexAuthors, $featuredWriterPubkeys))));

        $introText = trim($site->description);
        if ($introText === '') {
            $introText = $this->translator?->trans('unfold_about.default_intro', ['%title%' => $site->title])
                ?? sprintf('About %s.', $site->title);
        }

        return [
            '@site' => $siteContext,
            'site' => $siteContext,
            '@custom' => $this->buildCustomContext(),
            '@pageType' => 'about',
            ...$this->buildFooterContext($site),
            'about' => [
                'title' => $this->translate('unfold_about.title'),
                'intro_text' => $introText,
                'has_article' => $article !== null,
                'article_title' => $article?->title,
                'article_html' => $article !== null
                    ? $this->markdownToHtml($article->content, $article->coordinate)
                    : null,
                'magazine_people_label' => $this->translate('unfold_about.magazine_people'),
                'featured_writers_label' => $this->translate('unfold_about.featured_writers'),
                'magazine_people' => array_map(static fn(string $pubkey): array => $profiles[$pubkey], $indexAuthors),
                'featured_writers' => array_map(static fn(string $pubkey): array => $profiles[$pubkey], $featuredWriterPubkeys),
            ],
        ];
    }

    /**
     * @param list<string> $pubkeys
     * @return list<string>
     */
    private function uniquePubkeys(array $pubkeys): array
    {
        $unique = [];
        foreach ($pubkeys as $pubkey) {
            $pubkey = strtolower($pubkey);
            if (preg_match('/^[a-f0-9]{64}$/D', $pubkey) === 1) {
                $unique[$pubkey] = true;
            }
        }

        return array_keys($unique);
    }

    /**
     * @param list<string> $pubkeys
     * @return array<string, array{pubkey: string, name: string, picture: ?string, url: string}>
     */
    private function buildPeopleProfiles(array $pubkeys): array
    {
        if ($pubkeys === []) {
            return [];
        }

        try {
            $metadata = $this->profileMetadata->getMultipleMetadata($pubkeys);
        } catch (\Throwable) {
            $metadata = [];
        }

        $profiles = [];
        foreach ($pubkeys as $pubkey) {
            $person = $metadata[$pubkey] ?? null;
            $profiles[$pubkey] = [
                'pubkey' => $pubkey,
                'name' => $person?->displayName ?: $person?->name ?: substr($pubkey, 0, 8) . '…',
                'picture' => $person?->picture,
                'url' => rtrim($this->platformBaseUrl, '/') . '/p/' . $pubkey,
            ];
        }

        return $profiles;
    }

    /**
     * Build @site context (Ghost-compatible)
     *
     * @param CategoryData[] $categories
     */
    private function buildSiteContext(SiteConfig $site, array $categories, ?string $currentUrl): array
    {
        $navigation = array_map(fn(CategoryData $cat) => [
            'label' => $cat->title,
            'url' => '/' . $cat->slug,
            'slug' => $cat->slug,
            'current' => $currentUrl === '/' . $cat->slug,
        ], $categories);

        // Get magazine creator's lightning address from metadata
        $creatorMetadata = $this->profileMetadata->getMetadata($site->pubkey);
        $creatorLud16 = $creatorMetadata->lud16;
        $creatorLud06 = $creatorMetadata->lud06;

        // Handle lud16/lud06 as arrays (take first element)
        if (is_array($creatorLud16)) {
            $creatorLud16 = !empty($creatorLud16) ? $creatorLud16[0] : null;
        }
        if (is_array($creatorLud06)) {
            $creatorLud06 = !empty($creatorLud06) ? $creatorLud06[0] : null;
        }

        return [
            'title' => $site->title,
            'description' => $site->description,
            'logo' => $site->logo,
            'url' => '/',
            'navigation' => $navigation,
            'home_current' => $currentUrl === '/',
            'about_label' => $this->translate('unfold_about.title'),
            'about_current' => $currentUrl === '/about',
            'locale' => 'en',
            'members_enabled' => false,
            'creator_pubkey' => $site->pubkey,
            'creator_lud16' => $creatorLud16,
            'creator_lud06' => $creatorLud06,
        ];
    }

    /** Keep publication-owned links separate from the host platform's links. */
    private function buildFooterContext(SiteConfig $site): array
    {
        $platformBaseUrl = rtrim($this->platformBaseUrl, '/');

        return [
            'publication_footer' => [
                'title' => $site->title,
                'label' => $this->translate('unfold_footer.publication'),
                'owner_links_label' => $this->translate('unfold_footer.owner_links'),
                'support_label' => $this->translate('unfold_footer.support'),
                'navigation' => [
                    ['label' => $this->translate('unfold_footer.home'), 'url' => '/'],
                    ['label' => $this->translate('unfold_about.title'), 'url' => '/about'],
                    ['label' => $this->translate('unfold_footer.rss'), 'url' => '/rss.xml'],
                    ['label' => $this->translate('footer.sitemap'), 'url' => '/sitemap.xml'],
                ],
                'owner_links' => $site->footerLinks,
            ],
            'dn_footer' => [
                'label' => $this->translate('unfold_footer.platform'),
                'powered_by' => $this->translate('unfold_footer.powered_by'),
                'brand_url' => $platformBaseUrl . '/unfold',
                'links' => [
                    ['label' => $this->translate('footer.about'), 'url' => $platformBaseUrl . '/about'],
                    ['label' => $this->translate('footer.termsOfService'), 'url' => $platformBaseUrl . '/tos'],
                ],
            ],
        ];
    }

    private function translate(string $key): string
    {
        return $this->translator?->trans($key) ?? $key;
    }

    /**
     * Build @custom context (theme settings) with defaults
     */
    private function buildCustomContext(): array
    {
        return [
            'navigation_layout' => 'Logo on the left',
            'header_style' => 'Center aligned',
            'feed_layout' => 'Classic',
            'color_scheme' => 'Light',
            'post_image_style' => 'Wide',
            'title_font' => 'Modern sans-serif',
            'body_font' => 'Modern sans-serif',
            'show_publication_cover' => false,
            'email_signup_text' => 'Sign up for more like this.',
        ];
    }

    /**
     * Build post context for list views
     */
    private function buildPostListItemContext(PostData $post): array
    {
        // Fetch author metadata from Redis cache
        $authorMetadata = $this->profileMetadata->getMetadata($post->pubkey);
        $authorName = $authorMetadata->displayName ?: $authorMetadata->name ?: 'Author';

        return [
            'id' => $post->coordinate,
            'slug' => $post->slug,
            'title' => $post->title,
            'excerpt' => $post->summary,
            'url' => '/a/' . $post->slug,
            'feature_image' => $post->image,
            'published_at' => date('c', $post->publishedAt),
            'published_at_formatted' => $post->getPublishedDate(),
            'reading_time' => $this->estimateReadingTime($post->content),
            'primary_author' => [
                'id' => $post->pubkey,
                'name' => $authorName,
                'slug' => substr($post->pubkey, 0, 8),
                'profile_image' => $authorMetadata->picture,
            ],
        ];
    }

    /**
     * Build full post context for detail page
     */
    private function buildSinglePostContext(PostData $post, ?CategoryData $primaryCategory = null): array
    {
        // Fetch author metadata from Redis cache
        $authorMetadata = $this->profileMetadata->getMetadata($post->pubkey);
        $authorName = $authorMetadata->displayName ?: $authorMetadata->name ?: 'Author';

        // Use lightning address from post data first (if specified in article tags),
        // otherwise fall back to author's metadata
        $lud16 = $post->lud16 ?: $authorMetadata->lud16;
        $lud06 = $post->lud06 ?: $authorMetadata->lud06;

        // Handle lud16/lud06 as arrays (take first element)
        if (is_array($lud16)) {
            $lud16 = !empty($lud16) ? $lud16[0] : null;
        }
        if (is_array($lud06)) {
            $lud06 = !empty($lud06) ? $lud06[0] : null;
        }

        // Fetch comments and related zaps
        $comments = $this->buildCommentsContext($post->coordinate);
        $commentsCount = count(array_filter(
            $comments,
            static fn (array $item): bool => !($item['is_zap'] ?? false)
        ));

        return [
            'id' => $post->coordinate,
            'slug' => $post->slug,
            'title' => $post->title,
            'excerpt' => $post->summary,
            'html' => $this->markdownToHtml($post->content, $post->coordinate),
            'url' => '/a/' . $post->slug,
            'feature_image' => $post->image,
            'published_at' => date('c', $post->publishedAt),
            'published_at_formatted' => $post->getPublishedDate(),
            'reading_time' => $this->estimateReadingTime($post->content),
            'primary_author' => [
                'id' => $post->pubkey,
                'name' => $authorName,
                'slug' => substr($post->pubkey, 0, 8),
                'profile_image' => $authorMetadata->picture,
            ],
            'zap' => [
                'pubkey' => $post->pubkey,
                'lud16' => $lud16,
                'lud06' => $lud06,
                'splits' => $post->zapSplits,
            ],
            'comments' => $comments,
            'comments_count' => $commentsCount,
            'has_thread_activity' => [] !== $comments,
            'primary_tag' => $primaryCategory !== null ? [
                'name' => $primaryCategory->title,
                'slug' => $primaryCategory->slug,
                'url' => '/' . $primaryCategory->slug,
            ] : null,
        ];
    }

    /**
     * Build pagination context
     */
    private function buildPaginationContext(int $totalPosts, int $page = 1, int $perPage = 10): array
    {
        $totalPages = max(1, ceil($totalPosts / $perPage));

        return [
            'page' => $page,
            'pages' => $totalPages,
            'total' => $totalPosts,
            'limit' => $perPage,
            'prev' => $page > 1 ? $page - 1 : null,
            'next' => $page < $totalPages ? $page + 1 : null,
        ];
    }

    /**
     * Estimate reading time in minutes
     */
    private function estimateReadingTime(string $content): int
    {
        $wordCount = str_word_count(strip_tags($content));
        $readingTime = ceil($wordCount / 200); // Assume 200 words per minute

        return max(1, (int) $readingTime);
    }

    /**
     * Convert markdown to HTML using the CommonMark converter with Nostr link support.
     * Results are cached by event coordinate since content is immutable.
     */
    private function markdownToHtml(string $markdown, string $coordinate): string
    {
        // Addressable articles can be revised under the same coordinate.
        $cacheKey = 'unfold_html_' . hash('sha256', $coordinate . "\0" . $markdown);

        try {
            $item = $this->cache->getItem($cacheKey);

            if ($item->isHit()) {
                return $item->get();
            }

            // Convert markdown to HTML
            $html = $this->converter->convertToHTML($markdown);

            // Cache the result
            $item->set($html);
            $item->expiresAfter(self::CACHE_TTL);
            $this->cache->save($item);

            return $html;
        } catch (\Throwable $e) {
            // Fallback to basic HTML escaping if conversion or caching fails
            $html = htmlspecialchars($markdown, ENT_QUOTES, 'UTF-8');
            return nl2br($html);
        }
    }

    /**
     * Fetch and build comments context for a post coordinate
     *
     * @param string $coordinate Article coordinate (kind:pubkey:identifier)
     * @return array Array of comment objects
     */
    private function buildCommentsContext(string $coordinate): array
    {
        try {
            $events = $this->comments->findByCoordinate($coordinate);

            if (empty($events)) {
                return [];
            }

            // Extract unique pubkeys for metadata fetch
            $pubkeys = array_unique(array_filter(array_map(
                fn(Comment $event) => $event->pubkey,
                $events
            )));

            foreach ($events as $event) {
                $pubkeys = array_merge($pubkeys, CommentContentRenderer::profilePubkeys($event->content));
            }
            $pubkeys = array_values(array_unique($pubkeys));

            // Fetch all author and mentioned-profile metadata at once
            $metadataMap = [];
            if (!empty($pubkeys)) {
                $metadataArray = $this->profileMetadata->getMultipleMetadata($pubkeys);
                foreach ($metadataArray as $pubkey => $metadata) {
                    $metadataMap[$pubkey] = $metadata;
                }
            }

            // Build comments array
            $comments = [];
            foreach ($events as $event) {
                $pubkey = $event->pubkey;
                $metadata = $metadataMap[$pubkey] ?? null;

                $commentData = [
                    'id' => $event->id,
                    'kind' => $event->kind,
                    'pubkey' => $pubkey,
                    'content' => $event->content,
                    'content_html' => CommentContentRenderer::render($event->content, $metadataMap, $this->platformBaseUrl),
                    'created_at' => $event->createdAt,
                    'created_at_formatted' => date('F j, Y', $event->createdAt),
                    'author' => [
                        'name' => $metadata?->displayName ?: $metadata?->name ?: substr($pubkey, 0, 8) . '…',
                        'pic' => $metadata?->picture,
                        'pubkey' => $pubkey,
                    ],
                ];

                // Handle zaps (kind 9735)
                if ($event->kind === 9735) {
                    $commentData['is_zap'] = true;
                    $commentData['zap_amount'] = $this->extractZapAmount($event);
                    $commentData['zap_pubkey'] = $this->extractZapPubkey($event);
                } else {
                    $commentData['is_zap'] = false;
                }

                $comments[] = $commentData;
            }

            // Sort by created_at descending
            usort($comments, fn($a, $b) => $b['created_at'] - $a['created_at']);

            return $comments;
        } catch (\Throwable $e) {
            // DB unavailable or other error – return empty list
            return [];
        }
    }

    /**
     * Extract zap amount from a kind 9735 event (in sats)
     */
    private function extractZapAmount(Comment $event): ?int
    {
        $tags = $event->tags;
        if (!is_array($tags)) {
            return null;
        }

        foreach ($tags as $tag) {
            if (!is_array($tag) || count($tag) < 2) {
                continue;
            }

            if ($tag[0] === 'description') {
                try {
                    $description = json_decode($tag[1], true);
                    if (is_array($description) && isset($description['tags'])) {
                        // Look for amount tag in the description
                        foreach ($description['tags'] as $dtag) {
                            if (is_array($dtag) && count($dtag) >= 2 && $dtag[0] === 'amount') {
                                $msats = (int) $dtag[1];
                                return intdiv($msats, 1000); // Convert millisats to sats
                            }
                        }
                    }

                    // Fallback: check for bolt11 in description
                    if (is_array($description) && isset($description['bolt11'])) {
                        return $this->parseBolt11ToSats($description['bolt11']);
                    }
                } catch (\Throwable) {
                    // Ignore JSON decode errors
                }
            }

            // Check for bolt11 tag at top level
            if ($tag[0] === 'bolt11') {
                return $this->parseBolt11ToSats($tag[1]);
            }
        }

        return null;
    }

    /**
     * Extract zapper pubkey from a kind 9735 event
     */
    private function extractZapPubkey(Comment $event): ?string
    {
        $tags = $event->tags;
        if (!is_array($tags)) {
            return null;
        }

        foreach ($tags as $tag) {
            if (!is_array($tag) || count($tag) < 2) {
                continue;
            }

            if ($tag[0] === 'description') {
                try {
                    $description = json_decode($tag[1], true);
                    if (is_array($description) && isset($description['pubkey'])) {
                        return $description['pubkey'];
                    }
                } catch (\Throwable) {
                    // Ignore JSON decode errors
                }
            }

            if ($tag[0] === 'P') {
                return $tag[1];
            }
        }

        return null;
    }

    /**
     * Simple BOLT11 invoice parser to extract sats amount
     */
    private function parseBolt11ToSats(string $bolt11): ?int
    {
        // Match pattern: ln + amount + rest
        // Amount format: [0-9]+[munp]? where m=milli, u=micro, n=nano, p=pico
        if (preg_match('/^lnbc?(\d+)([munp])?/i', strtolower($bolt11), $matches)) {
            $amount = (int) $matches[1];
            $unit = $matches[2] ?? '';

            return match ($unit) {
                'm' => intdiv($amount, 1000), // millis to sats
                'u' => 0, // micros → too small to be sats
                'n' => 0, // nanos → too small
                'p' => 0, // picos → too small
                default => $amount, // no unit = sats
            };
        }

        return null;
    }
}
