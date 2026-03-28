<?php

declare(strict_types=1);

namespace App\Controller\Administration;

use App\Enum\KindsEnum;
use App\Service\GenericEventProjector;
use App\Service\Nostr\NostrClient;
use App\Service\Nostr\UserRelayListService;
use App\Service\RSS\RssFeedService;
use App\Util\NostrKeyUtil;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use swentel\nostr\Event\Event as NostrEvent;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\AsciiSlugger;

#[Route('/admin/rss', name: 'admin_rss_')]
#[IsGranted('ROLE_ADMIN')]
class AdminRssController extends AbstractController
{
    public function __construct(
        private readonly RssFeedService $rssFeedService,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * RSS admin index — submit an RSS feed URL to fetch and preview articles.
     */
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/rss_submit.html.twig', [
            'feedMeta' => null,
            'articles' => [],
            'feedUrl' => '',
            'error' => null,
        ]);
    }

    /**
     * Fetch and preview articles from an RSS feed.
     */
    #[Route('/fetch', name: 'fetch', methods: ['POST'])]
    public function fetch(Request $request): Response
    {
        $feedUrl = trim($request->request->get('feed_url', ''));
        $error = null;
        $feedMeta = null;
        $articles = [];

        if ($feedUrl === '') {
            $error = 'Please enter an RSS feed URL.';
        } else {
            try {
                $feed = $this->rssFeedService->fetchFeed($feedUrl);
                $feedMeta = $feed['feed'] ?? null;
                $articles = $feed['items'] ?? [];

                // Check for duplicates already in the database
                $slugger = new AsciiSlugger();
                foreach ($articles as &$item) {
                    $slug = $this->generateSlug($slugger, $item['title'] ?? '', $item['pubDate'] ?? null);
                    $item['slug'] = $slug;
                    $item['existsInDb'] = $this->articleExistsBySourceUrl($item['link'] ?? '');
                }
                unset($item);

                // Store in session for the review step
                $request->getSession()->set('rss_feed_url', $feedUrl);
                $request->getSession()->set('rss_feed_meta', $feedMeta);
                $request->getSession()->set('rss_articles', $articles);
            } catch (\Exception $e) {
                $error = 'Failed to fetch feed: ' . $e->getMessage();
                $this->logger->error('RSS fetch error', [
                    'url' => $feedUrl,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $this->render('admin/rss_submit.html.twig', [
            'feedMeta' => $feedMeta,
            'articles' => $articles,
            'feedUrl' => $feedUrl,
            'error' => $error,
        ]);
    }

    /**
     * Review selected RSS articles and prepare event skeletons for signing.
     */
    #[Route('/review', name: 'review', methods: ['POST'])]
    public function review(Request $request): Response
    {
        $articles = $request->getSession()->get('rss_articles', []);
        $feedUrl = $request->getSession()->get('rss_feed_url', '');
        $feedMeta = $request->getSession()->get('rss_feed_meta', null);

        // Get selected article indices
        $selectedIndices = $request->request->all('selected');
        if (empty($selectedIndices)) {
            $this->addFlash('warning', 'No articles selected for review.');
            return $this->redirectToRoute('admin_rss_index');
        }

        // Build event skeletons for selected articles
        $skeletons = [];
        $slugger = new AsciiSlugger();
        foreach ($selectedIndices as $idx) {
            $idx = (int) $idx;
            if (!isset($articles[$idx])) {
                continue;
            }

            $item = $articles[$idx];
            $skeletons[] = $this->buildEventSkeleton($slugger, $item, $feedUrl);
        }

        return $this->render('admin/rss_review.html.twig', [
            'skeletons' => $skeletons,
            'feedMeta' => $feedMeta,
            'feedUrl' => $feedUrl,
        ]);
    }

    /**
     * API endpoint: receive a signed event from the client-side signer,
     * persist it locally and publish to relays.
     */
    #[Route('/publish', name: 'publish', methods: ['POST'])]
    public function publish(
        Request $request,
        NostrClient $nostrClient,
        GenericEventProjector $genericEventProjector,
        UserRelayListService $userRelayListService,
        LoggerInterface $logger,
    ): JsonResponse {
        try {
            $data = json_decode($request->getContent(), true);
            if (!$data || !isset($data['event'])) {
                return new JsonResponse(['error' => 'Missing signed event'], 400);
            }

            $signedEvent = $data['event'];

            // Verify event signature
            $eventObj = NostrEvent::fromVerified((object) $signedEvent);
            if (!$eventObj->verify()) {
                return new JsonResponse(['error' => 'Event signature verification failed'], 400);
            }

            // Persist to local database via GenericEventProjector
            $eventEntity = $genericEventProjector->projectEventFromNostrEvent(
                (object) $signedEvent,
                'admin-rss-import'
            );

            // Get user's write relays
            $user = $this->getUser();
            $relays = [];
            if ($user) {
                try {
                    $pubkeyHex = NostrKeyUtil::npubToHex($user->getUserIdentifier());
                    $relays = $userRelayListService->getRelaysForPublishing($pubkeyHex);
                } catch (\Exception $e) {
                    $logger->warning('Failed to get user relays for RSS publish', [
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Publish to relays
            $relayResults = [];
            try {
                $rawResults = $nostrClient->publishEvent($eventObj, $relays, 10);
                $relayResults = $this->transformRelayResults($rawResults);
            } catch (\Exception $e) {
                $logger->error('Failed to publish RSS article to relays', [
                    'error' => $e->getMessage(),
                    'event_id' => $signedEvent['id'] ?? 'unknown',
                ]);
                $relayResults = [['relay' => 'error', 'success' => false, 'message' => $e->getMessage()]];
            }

            $title = '';
            foreach ($signedEvent['tags'] ?? [] as $tag) {
                if ($tag[0] === 'title' && !empty($tag[1])) {
                    $title = $tag[1];
                    break;
                }
            }

            return new JsonResponse([
                'success' => true,
                'message' => sprintf('Article "%s" published successfully.', $title),
                'eventId' => $signedEvent['id'] ?? null,
                'relayResults' => $relayResults,
            ]);
        } catch (\Exception $e) {
            $logger->error('RSS publish error', ['error' => $e->getMessage()]);
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Build an unsigned Nostr kind 30023 event skeleton from an RSS item.
     */
    private function buildEventSkeleton(AsciiSlugger $slugger, array $item, string $feedUrl): array
    {
        $slug = $this->generateSlug($slugger, $item['title'] ?? '', $item['pubDate'] ?? null);

        $content = $item['content'] ?? $item['description'] ?? '';
        // Strip HTML for Nostr longform (markdown-ish)
        $content = $this->htmlToMarkdown($content);

        $tags = [];
        $tags[] = ['d', $slug];

        if (!empty($item['title'])) {
            $tags[] = ['title', $item['title']];
        }

        if (!empty($item['description'])) {
            $summary = $this->htmlToPlainText($item['description']);
            if (mb_strlen($summary) > 300) {
                $summary = mb_substr($summary, 0, 297) . '...';
            }
            $tags[] = ['summary', $summary];
        }

        if (!empty($item['image'])) {
            $tags[] = ['image', $item['image']];
        }

        if ($item['pubDate'] !== null) {
            $tags[] = ['published_at', (string) $item['pubDate']];
        }

        if (!empty($item['link'])) {
            $tags[] = ['r', $item['link']];
        }

        // Add categories as 't' tags (or 'l' for lang:*, 'author' for author:*)
        foreach ($item['categories'] ?? [] as $category) {
            $c = trim($category);
            if ($c === '') {
                continue;
            }
            $lower = strtolower($c);
            if (str_starts_with($lower, 'lang:')) {
                $langTag = trim(substr($c, 5));
                if ($langTag !== '') {
                    $tags[] = ['l', $langTag];
                }
            } elseif (str_starts_with($lower, 'author:')) {
                $authorName = trim(substr($c, 7));
                if ($authorName !== '') {
                    $tags[] = ['author', $authorName];
                }
            } else {
                $tags[] = ['t', $lower];
            }
        }

        $tags[] = ['client', 'Decent Newsroom'];

        return [
            'kind' => KindsEnum::LONGFORM->value,
            'created_at' => $item['pubDate'] ?? time(),
            'content' => $content,
            'tags' => $tags,
            // pubkey will be filled by the client-side signer
            '_meta' => [
                'title' => $item['title'] ?? '',
                'link' => $item['link'] ?? '',
                'image' => $item['image'] ?? null,
                'description' => $item['description'] ?? '',
                'slug' => $slug,
                'existsInDb' => $item['existsInDb'] ?? false,
            ],
        ];
    }

    private function generateSlug(AsciiSlugger $slugger, string $title, ?int $pubDate): string
    {
        $baseSlug = $slugger->slug($title)->lower()->toString();
        if (strlen($baseSlug) > 50) {
            $baseSlug = substr($baseSlug, 0, 50);
        }
        $timestamp = $pubDate ? date('Y-m-d-His', $pubDate) : date('Y-m-d-His');
        return $baseSlug . '-' . $timestamp;
    }

    private function htmlToPlainText(?string $html): string
    {
        if (empty($html)) {
            return '';
        }
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', $text);
        return trim($text);
    }

    private function htmlToMarkdown(?string $html): string
    {
        if (empty($html)) {
            return '';
        }

        // Basic HTML → Markdown conversion
        $text = $html;

        // Headers
        $text = preg_replace('/<h1[^>]*>(.*?)<\/h1>/is', "# $1\n\n", $text);
        $text = preg_replace('/<h2[^>]*>(.*?)<\/h2>/is', "## $1\n\n", $text);
        $text = preg_replace('/<h3[^>]*>(.*?)<\/h3>/is', "### $1\n\n", $text);

        // Paragraphs
        $text = preg_replace('/<p[^>]*>(.*?)<\/p>/is', "$1\n\n", $text);

        // Bold/italic
        $text = preg_replace('/<(strong|b)>(.*?)<\/\1>/is', "**$2**", $text);
        $text = preg_replace('/<(em|i)>(.*?)<\/\1>/is', "*$2*", $text);

        // Links
        $text = preg_replace('/<a[^>]+href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', "[$2]($1)", $text);

        // Images
        $text = preg_replace('/<img[^>]+src=["\']([^"\']+)["\'][^>]*alt=["\']([^"\']*)["\'][^>]*\/?>/is', "![$2]($1)", $text);
        $text = preg_replace('/<img[^>]+src=["\']([^"\']+)["\'][^>]*\/?>/is', "![]($1)", $text);

        // Line breaks
        $text = preg_replace('/<br\s*\/?>/i', "\n", $text);

        // Lists
        $text = preg_replace('/<li[^>]*>(.*?)<\/li>/is', "- $1\n", $text);
        $text = preg_replace('/<\/?[uo]l[^>]*>/is', "\n", $text);

        // Blockquotes
        $text = preg_replace('/<blockquote[^>]*>(.*?)<\/blockquote>/is', "> $1\n\n", $text);

        // Code
        $text = preg_replace('/<code>(.*?)<\/code>/is', "`$1`", $text);
        $text = preg_replace('/<pre[^>]*>(.*?)<\/pre>/is', "```\n$1\n```\n\n", $text);

        // Strip remaining tags
        $text = strip_tags($text);

        // Decode HTML entities
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Clean up whitespace (collapse more than 2 newlines)
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }

    private function articleExistsBySourceUrl(string $url): bool
    {
        if (empty($url)) {
            return false;
        }

        // Check if an article with this source URL already exists.
        // Uses PostgreSQL jsonb containment (@>) to find events whose tags
        // array contains an ["r", "<url>"] element.
        $conn = $this->entityManager->getConnection();
        $count = (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM event WHERE kind = ? AND tags @> ?::jsonb',
            [KindsEnum::LONGFORM->value, json_encode([['r', $url]])]
        );

        return $count > 0;
    }

    private function transformRelayResults(array $rawResults): array
    {
        $results = [];
        foreach ($rawResults as $relayUrl => $response) {
            $result = [
                'relay' => $relayUrl,
                'success' => false,
                'type' => 'unknown',
                'message' => '',
            ];

            if (is_object($response)) {
                $type = $response->type ?? '';
                if ($type === 'OK') {
                    $result['success'] = (bool) ($response->isSuccess ?? $response->status ?? false);
                    $result['type'] = 'ok';
                    $result['message'] = $response->message ?? '';
                } elseif ($type === 'AUTH') {
                    $result['success'] = false;
                    $result['type'] = 'auth';
                    $result['message'] = 'Authentication required';
                } elseif ($type === 'NOTICE') {
                    $result['success'] = false;
                    $result['type'] = 'notice';
                    $result['message'] = $response->message ?? '';
                } elseif (isset($response->isSuccess)) {
                    $result['success'] = (bool) $response->isSuccess;
                    $result['type'] = strtolower($type ?: 'ok');
                    $result['message'] = $response->message ?? '';
                }
            } elseif (is_array($response)) {
                $result['success'] = (bool) ($response['ok'] ?? false);
                $result['type'] = 'ok';
                $result['message'] = $response['message'] ?? '';
            }

            $results[] = $result;
        }
        return $results;
    }
}
