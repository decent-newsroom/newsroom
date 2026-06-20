<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Article;
use App\Entity\Event;
use App\Enum\KindsEnum;
use App\Repository\ArticleRepository;
use App\Util\NostrKeyUtil;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;

#[AsCommand(
    name: 'app:cache-sitemap',
    description: 'Pre-generate and cache the XML sitemap to Redis'
)]
class CacheSitemapCommand extends Command
{
    private const SITEMAP_CACHE_KEY = 'sitemap:xml';
    private const SITEMAP_TTL = 600; // 10 minutes

    public function __construct(
        private readonly ArticleRepository $articleRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly RouterInterface $router,
        private readonly \Redis $redis,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<comment>Generating sitemap...</comment>');

        try {
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
                    'loc'        => $this->router->generate($item['route'], [], UrlGeneratorInterface::ABSOLUTE_URL),
                    'changefreq' => $item['changefreq'],
                    'priority'   => $item['priority'],
                    'lastmod'    => null,
                ];
            }

            $output->writeln(sprintf('<info>  ✓ Added %d static pages</info>', count($staticRoutes)));

            // ── Published articles (kind 30023, newest 1000) ───────────────────
            $articles = $this->articleRepository->findLatestArticles(1000);
            $articleCount = 0;

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
                    'loc'        => $this->router->generate('author-article-slug', [
                        'npub' => $npub,
                        'slug' => $slug,
                    ], UrlGeneratorInterface::ABSOLUTE_URL),
                    'changefreq' => 'monthly',
                    'priority'   => '0.7',
                    'lastmod'    => $lastmod instanceof \DateTimeInterface
                        ? $lastmod->format('Y-m-d')
                        : null,
                ];
                $articleCount++;
            }

            $output->writeln(sprintf('<info>  ✓ Added %d published articles</info>', $articleCount));

            // ── Magazines (kind 30040) ─────────────────────────────────────────
            $magazineEvents = $this->entityManager->getRepository(Event::class)->findBy(
                ['kind' => KindsEnum::PUBLICATION_INDEX],
                ['created_at' => 'DESC']
            );

            // Deduplicate: keep only the latest version per slug
            $seenSlugs = [];
            $magazineCount = 0;

            foreach ($magazineEvents as $magazine) {
                $slug = $magazine->getSlug();
                if (!$slug || isset($seenSlugs[$slug])) {
                    continue;
                }
                $seenSlugs[$slug] = true;

                $urls[] = [
                    'loc'        => $this->router->generate('magazine-index', ['mag' => $slug], UrlGeneratorInterface::ABSOLUTE_URL),
                    'changefreq' => 'weekly',
                    'priority'   => '0.7',
                    'lastmod'    => (new \DateTime())->setTimestamp($magazine->getCreatedAt())->format('Y-m-d'),
                ];
                $magazineCount++;
            }

            $output->writeln(sprintf('<info>  ✓ Added %d magazines</info>', $magazineCount));

            // ── Generate XML ──────────────────────────────────────────────────────
            $xml = $this->generateSitemapXml($urls);

            // ── Store in Redis ────────────────────────────────────────────────────
            $this->redis->setex(self::SITEMAP_CACHE_KEY, self::SITEMAP_TTL, $xml);

            $output->writeln('');
            $output->writeln(sprintf(
                '<info>✓ Successfully cached sitemap (%d URLs, %d bytes, %d min TTL)</info>',
                count($urls),
                strlen($xml),
                self::SITEMAP_TTL / 60
            ));

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $output->writeln(sprintf('<error>✗ Failed to cache sitemap: %s</error>', $e->getMessage()));
            return Command::FAILURE;
        }
    }

    private function generateSitemapXml(array $urls): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        foreach ($urls as $url) {
            $xml .= '  <url>' . "\n";
            $xml .= '    <loc>' . htmlspecialchars($url['loc'], ENT_XML1) . '</loc>' . "\n";

            if ($url['lastmod']) {
                $xml .= '    <lastmod>' . htmlspecialchars($url['lastmod']) . '</lastmod>' . "\n";
            }

            $xml .= '    <changefreq>' . htmlspecialchars($url['changefreq']) . '</changefreq>' . "\n";
            $xml .= '    <priority>' . htmlspecialchars($url['priority']) . '</priority>' . "\n";
            $xml .= '  </url>' . "\n";
        }

        $xml .= '</urlset>';

        return $xml;
    }
}


