<?php

declare(strict_types=1);

namespace App\Controller\Administration;

use FOS\ElasticaBundle\Finder\FinderInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/magazines')]
#[IsGranted('ROLE_ADMIN')]
class MagazineEditorController extends AbstractController
{
    #[Route('/edit/{slug}', name: 'admin_magazine_edit', methods: ['GET'])]
    public function edit(
        string $slug,
        Request $request,
        CacheItemPoolInterface $cachePool,
        FinderInterface $finder
    ): Response {
        $key = 'magazine-' . $slug;
        $item = $cachePool->getItem($key);
        if (!$item->isHit()) {
            throw $this->createNotFoundException('Index not found');
        }
        $event = $item->get();
        if (!method_exists($event, 'getTags')) {
            throw $this->createNotFoundException('Invalid index');
        }

        $tags = (array) $event->getTags();
        $title = $this->getTagValue($tags, 'title') ?? $slug;
        $type = $this->detectIndexType($tags);

        // Search
        $q = trim((string) $request->query->get('q', ''));
        $results = [];
        if ($q !== '') {
            $query = [
                'query' => [
                    'multi_match' => [
                        'query' => $q,
                        'fields' => ['title^3', 'summary', 'content'],
                    ],
                ],
                'size' => 50,
            ];
            $results = $finder->find($query);
        }

        // Current entries from 'a' tags
        $current = [];
        foreach ($tags as $t) {
            if (is_array($t) && ($t[0] ?? null) === 'a' && isset($t[1])) {
                $coord = (string) $t[1];
                $parts = explode(':', $coord, 3);
                if (count($parts) === 3) {
                    $current[] = [
                        'coord' => $coord,
                        'kind' => $parts[0],
                        'pubkey' => $parts[1],
                        'slug' => $parts[2],
                    ];
                }
            }
        }

        return $this->render('admin/magazine_editor.html.twig', [
            'slug' => $slug,
            'title' => $title,
            'type' => $type,
            'q' => $q,
            'results' => $results,
            'current' => $current,
            'csrfToken' => $this->container->get('security.csrf.token_manager')->getToken('admin_mag_edit')->getValue(),
        ]);
    }

    #[Route('/edit/{slug}/add-article', name: 'admin_magazine_add_article', methods: ['POST'])]
    public function addArticle(
        string $slug,
        Request $request,
        CacheItemPoolInterface $cachePool,
    ): RedirectResponse {

        $articleSlug = trim((string) $request->request->get('article_slug', ''));
        $pubkey = trim((string) $request->request->get('article_pubkey', ''));
        if ($articleSlug === '' || $pubkey === '') {
            return $this->redirectToRoute('admin_magazine_edit', ['slug' => $slug, 'q' => $request->request->get('q', '')]);
        }
        $coord = sprintf('30023:%s:%s', $pubkey, $articleSlug);
        $key = 'magazine-' . $slug;
        $item = $cachePool->getItem($key);
        if (!$item->isHit()) {
            throw $this->createNotFoundException('Index not found');
        }
        $event = $item->get();
        $tags = (array) $event->getTags();
        foreach ($tags as $t) {
            if (is_array($t) && ($t[0] ?? null) === 'a' && ($t[1] ?? null) === $coord) {
                return $this->redirectToRoute('admin_magazine_edit', ['slug' => $slug, 'q' => $request->request->get('q', '')]);
            }
        }
        array_unshift($tags, ['a', $coord]);
        $event->setTags($tags);
        $item->set($event);
        $cachePool->save($item);
        return $this->redirectToRoute('admin_magazine_edit', ['slug' => $slug, 'q' => $request->request->get('q', '')]);
    }

    #[Route('/edit/{slug}/remove-article', name: 'admin_magazine_remove_article', methods: ['POST'])]
    public function removeArticle(
        string $slug,
        Request $request,
        CacheItemPoolInterface $cachePool
    ): RedirectResponse {
        $articleSlug = trim((string) $request->request->get('article_slug', ''));
        if ($articleSlug === '') {
            return $this->redirectToRoute('admin_magazine_edit', ['slug' => $slug]);
        }
        $key = 'magazine-' . $slug;
        $item = $cachePool->getItem($key);
        if (!$item->isHit()) {
            throw $this->createNotFoundException('Index not found');
        }
        $event = $item->get();
        $tags = (array) $event->getTags();
        $tags = array_values(array_filter($tags, function ($t) use ($articleSlug) {
            if (!is_array($t) || ($t[0] ?? null) !== 'a' || !isset($t[1])) {
                return true;
            }
            $parts = explode(':', (string) $t[1], 3);
            return (($parts[2] ?? '') !== $articleSlug);
        }));
        $event->setTags($tags);
        $item->set($event);
        $cachePool->save($item);
        return $this->redirectToRoute('admin_magazine_edit', ['slug' => $slug]);
    }

    private function detectIndexType(array $tags): string
    {
        foreach ($tags as $t) {
            if (is_array($t) && ($t[0] ?? null) === 'a' && isset($t[1]) && str_starts_with((string) $t[1], '30040:')) {
                return 'magazine';
            }
        }
        return 'category';
    }

    private function getTagValue(array $tags, string $name): ?string
    {
        foreach ($tags as $t) {
            if (is_array($t) && ($t[0] ?? null) === $name && isset($t[1])) {
                return (string) $t[1];
            }
        }
        return null;
    }
}

