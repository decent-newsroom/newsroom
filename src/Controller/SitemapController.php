<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Article;
use App\Entity\Event;
use App\Enum\KindsEnum;
use App\Repository\ArticleRepository;
use App\Util\NostrKeyUtil;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class SitemapController extends AbstractController
{
    /**
     * XML sitemap listing static pages, published articles, and magazines.
     * Cached for 15 minutes in the HTTP layer.
     */
    #[Route('/sitemap.xml', name: 'sitemap', methods: ['GET'])]
    public function sitemap(
        ArticleRepository $articleRepository,
        EntityManagerInterface $entityManager,
    ): Response {
        $urls = [];

        // ── Static pages ──────────────────────────────────────────────────────
        $staticRoutes = [
            ['route' => 'home',              'priority' => '1.0', 'changefreq' => 'daily'],
            ['route' => 'discover',          'priority' => '0.9', 'changefreq' => 'hourly'],
            ['route' => 'newsstand',         'priority' => '0.8', 'changefreq' => 'daily'],
            ['route' => 'follow_packs',      'priority' => '0.7', 'changefreq' => 'weekly'],
            ['route' => 'app_static_about',  'priority' => '0.6', 'changefreq' => 'monthly'],
            ['route' => 'app_static_pricing','priority' => '0.6', 'changefreq' => 'monthly'],
            ['route' => 'app_static_tos',    'priority' => '0.4', 'changefreq' => 'monthly'],
            ['route' => 'app_static_changelog', 'priority' => '0.5', 'changefreq' => 'weekly'],
            ['route' => 'app_static_roadmap',   'priority' => '0.5', 'changefreq' => 'weekly'],
        ];

        foreach ($staticRoutes as $item) {
            $urls[] = [
                'loc'        => $this->generateUrl($item['route'], [], UrlGeneratorInterface::ABSOLUTE_URL),
                'changefreq' => $item['changefreq'],
                'priority'   => $item['priority'],
                'lastmod'    => null,
            ];
        }

        // ── Published articles (kind 30023, newest 1000) ───────────────────
        $articles = $articleRepository->findLatestArticles(1000);

        foreach ($articles as $article) {
            $pubkey = $article->getPubkey();
            $slug   = $article->getSlug();

            if (!$pubkey || !$slug) {
                continue;
            }

            try {
                $npub = NostrKeyUtil::hexToNpub($pubkey);
            } catch (\Throwable) {
                continue;
            }

            $lastmod = $article->getPublishedAt() ?? $article->getCreatedAt();

            $urls[] = [
                'loc'        => $this->generateUrl('author-article-slug', [
                    'npub' => $npub,
                    'slug' => $slug,
                ], UrlGeneratorInterface::ABSOLUTE_URL),
                'changefreq' => 'monthly',
                'priority'   => '0.7',
                'lastmod'    => $lastmod instanceof \DateTimeInterface
                    ? $lastmod->format('Y-m-d')
                    : null,
            ];
        }

        // ── Magazines (kind 30040) ─────────────────────────────────────────
        $magazineEvents = $entityManager->getRepository(Event::class)->findBy(
            ['kind' => KindsEnum::PUBLICATION_INDEX],
            ['created_at' => 'DESC']
        );

        // Deduplicate: keep only the latest version per slug
        $seenSlugs = [];
        foreach ($magazineEvents as $magazine) {
            $slug = $magazine->getSlug();
            if (!$slug || isset($seenSlugs[$slug])) {
                continue;
            }
            $seenSlugs[$slug] = true;

            $urls[] = [
                'loc'        => $this->generateUrl('magazine-index', ['mag' => $slug], UrlGeneratorInterface::ABSOLUTE_URL),
                'changefreq' => 'weekly',
                'priority'   => '0.7',
                'lastmod'    => (new \DateTime())->setTimestamp($magazine->getCreatedAt())->format('Y-m-d'),
            ];
        }

        $response = new Response(
            $this->renderView('sitemap.xml.twig', ['urls' => $urls]),
            200,
            [
                'Content-Type'  => 'application/xml; charset=UTF-8',
                'Cache-Control' => 'public, max-age=900', // 15 minutes
            ]
        );

        return $response;
    }
}

