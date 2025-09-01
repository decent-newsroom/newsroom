<?php

declare(strict_types=1);

namespace App\Controller\Administration;

use App\Entity\Article;
use Doctrine\ORM\EntityManagerInterface;
use Redis as RedisClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Cache\CacheInterface;

class MagazineAdminController extends AbstractController
{
    #[Route('/admin/magazines', name: 'admin_magazines')]
    #[IsGranted('ROLE_ADMIN')]
    public function index(RedisClient $redis, CacheInterface $redisCache, EntityManagerInterface $em): Response
    {
        // 1) Collect known top-level magazine slugs from Redis set (populated on publish)
        $slugs = [];
        try {
            $members = $redis->sMembers('magazine_slugs');
            if (is_array($members)) {
                $slugs = array_values(array_unique(array_filter($members)));
            }
        } catch (\Throwable) {
            // ignore set errors
        }

        // 2) Ensure the known main magazine is included if present in cache
        try {
            $main = $redisCache->get('magazine-newsroom-magazine-by-newsroom', fn() => null);
            if ($main) {
                if (!in_array('newsroom-magazine-by-newsroom', $slugs, true)) {
                    $slugs[] = 'newsroom-magazine-by-newsroom';
                }
            }
        } catch (\Throwable) {
            // ignore
        }

        // 3) Load magazine events and build structure
        $magazines = [];

        // Helper to parse tags
        $parse = function($event): array {
            $title = null; $slug = null; $a = [];
            foreach ((array) $event->getTags() as $tag) {
                if (!is_array($tag) || !isset($tag[0])) continue;
                if ($tag[0] === 'title' && isset($tag[1])) $title = $tag[1];
                if ($tag[0] === 'd' && isset($tag[1])) $slug = $tag[1];
                if ($tag[0] === 'a' && isset($tag[1])) $a[] = $tag[1];
            }
            return [
                'title' => $title ?? ($slug ?? '(untitled)'),
                'slug' => $slug ?? '',
                'a' => $a,
            ];
        };

        foreach ($slugs as $slug) {
            $event = $redisCache->get('magazine-' . $slug, fn() => null);
            if (!$event || !method_exists($event, 'getTags')) {
                continue;
            }
            $data = $parse($event);

            // Resolve categories
            $categories = [];
            foreach ($data['a'] as $coord) {
                if (!str_starts_with((string)$coord, '30040:')) continue;
                $parts = explode(':', (string)$coord, 3);
                if (count($parts) !== 3) continue;
                $catSlug = $parts[2];
                $catEvent = $redisCache->get('magazine-' . $catSlug, fn() => null);
                if (!$catEvent || !method_exists($catEvent, 'getTags')) continue;
                $catData = $parse($catEvent);

                // Files under category from its 'a' coordinates
                $files = [];
                $repo = $em->getRepository(Article::class);
                foreach ($catData['a'] as $aCoord) {
                    $partsA = explode(':', (string)$aCoord, 3);
                    if (count($partsA) !== 3) continue;
                    $artSlug = $partsA[2];
                    $authorPubkey = $partsA[1] ?? '';
                    $title = null;
                    if ($artSlug !== '') {
                        $article = $repo->findOneBy(['slug' => $artSlug]);
                        if ($article) { $title = $article->getTitle(); }
                    }
                    $files[] = [
                        'name' => $title ?? $artSlug,
                        'slug' => $artSlug,
                        'coordinate' => $aCoord,
                        'authorPubkey' => $authorPubkey,
                    ];
                }

                $categories[] = [
                    'name' => $catData['title'],
                    'slug' => $catData['slug'],
                    'files' => $files,
                ];
            }

            $magazines[] = [
                'name' => $data['title'],
                'slug' => $data['slug'],
                'categories' => $categories,
            ];
        }

        // Sort alphabetically
        usort($magazines, fn($a, $b) => strcmp($a['name'], $b['name']));
        foreach ($magazines as &$mag) {
            usort($mag['categories'], fn($a, $b) => strcmp($a['name'], $b['name']));
            foreach ($mag['categories'] as &$cat) {
                usort($cat['files'], fn($a, $b) => strcmp($a['name'], $b['name']));
            }
        }

        return $this->render('admin/magazines.html.twig', [
            'magazines' => $magazines,
        ]);
    }
}
