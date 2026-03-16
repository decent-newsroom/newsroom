<?php

declare(strict_types=1);

namespace App\Controller\Administration;

use App\Entity\Event;
use App\Enum\KindsEnum;
use App\Service\Search\ArticleSearchInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Admin magazine editor — view and edit index/category event contents.
 *
 * Reads from the event table (source of truth). Mutations create a new
 * version of the event in the DB (Nostr parameterized replaceable semantics:
 * same kind + pubkey + d-tag, newer created_at wins).
 */
#[Route('/admin/magazines')]
#[IsGranted('ROLE_ADMIN')]
class MagazineEditorController extends AbstractController
{
    #[Route('/edit/{slug}', name: 'admin_magazine_edit', methods: ['GET'])]
    public function edit(
        string $slug,
        Request $request,
        EntityManagerInterface $em,
        ArticleSearchInterface $articleSearch,
    ): Response {
        $event = $this->findIndexEvent($em, $slug);
        if (!$event) {
            throw $this->createNotFoundException('Index not found');
        }

        $tags = $event->getTags();
        $title = $this->getTagValue($tags, 'title') ?? $slug;
        $type = $this->detectIndexType($tags);

        // Search
        $q = trim((string) $request->query->get('q', ''));
        $results = [];
        if ($q !== '') {
            $results = $articleSearch->search($q, 50);
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
        EntityManagerInterface $em,
        LoggerInterface $logger,
    ): RedirectResponse {
        $articleSlug = trim((string) $request->request->get('article_slug', ''));
        $pubkey = trim((string) $request->request->get('article_pubkey', ''));
        if ($articleSlug === '' || $pubkey === '') {
            return $this->redirectToRoute('admin_magazine_edit', ['slug' => $slug, 'q' => $request->request->get('q', '')]);
        }

        $event = $this->findIndexEvent($em, $slug);
        if (!$event) {
            throw $this->createNotFoundException('Index not found');
        }

        $coord = sprintf('30023:%s:%s', $pubkey, $articleSlug);
        $tags = $event->getTags();

        // Check for duplicate
        foreach ($tags as $t) {
            if (is_array($t) && ($t[0] ?? null) === 'a' && ($t[1] ?? null) === $coord) {
                return $this->redirectToRoute('admin_magazine_edit', ['slug' => $slug, 'q' => $request->request->get('q', '')]);
            }
        }

        // Prepend the new article coordinate
        array_unshift($tags, ['a', $coord]);

        $this->persistUpdatedEvent($em, $event, $tags, $logger);

        return $this->redirectToRoute('admin_magazine_edit', ['slug' => $slug, 'q' => $request->request->get('q', '')]);
    }

    #[Route('/edit/{slug}/remove-article', name: 'admin_magazine_remove_article', methods: ['POST'])]
    public function removeArticle(
        string $slug,
        Request $request,
        EntityManagerInterface $em,
        LoggerInterface $logger,
    ): RedirectResponse {
        $articleSlug = trim((string) $request->request->get('article_slug', ''));
        if ($articleSlug === '') {
            return $this->redirectToRoute('admin_magazine_edit', ['slug' => $slug]);
        }

        $event = $this->findIndexEvent($em, $slug);
        if (!$event) {
            throw $this->createNotFoundException('Index not found');
        }

        $tags = $event->getTags();
        $tags = array_values(array_filter($tags, function ($t) use ($articleSlug) {
            if (!is_array($t) || ($t[0] ?? null) !== 'a' || !isset($t[1])) {
                return true;
            }
            $parts = explode(':', (string) $t[1], 3);
            return (($parts[2] ?? '') !== $articleSlug);
        }));

        $this->persistUpdatedEvent($em, $event, $tags, $logger);

        return $this->redirectToRoute('admin_magazine_edit', ['slug' => $slug]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Find the newest index event (kind 30040) by d-tag slug.
     */
    private function findIndexEvent(EntityManagerInterface $em, string $slug): ?Event
    {
        return $em->getRepository(Event::class)
            ->createQueryBuilder('e')
            ->where('e.kind = :kind')
            ->andWhere('e.dTag = :slug')
            ->setParameter('kind', KindsEnum::PUBLICATION_INDEX->value)
            ->setParameter('slug', $slug)
            ->orderBy('e.created_at', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Persist an updated version of the index event with new tags.
     *
     * Creates a new Event row (new event ID) with an incremented created_at
     * so it becomes the current version for this coordinate.
     */
    private function persistUpdatedEvent(
        EntityManagerInterface $em,
        Event $original,
        array $newTags,
        LoggerInterface $logger,
    ): void {
        $updated = new Event();
        $updated->setPubkey($original->getPubkey());
        $updated->setKind($original->getKind());
        $updated->setTags($newTags);
        $updated->setContent($original->getContent());
        $updated->setSig(''); // unsigned admin edit
        $updated->setCreatedAt(max($original->getCreatedAt() + 1, time()));
        // Deterministic ID so re-edits don't pile up duplicates
        $updated->setId(hash('sha256', json_encode([
            $updated->getKind(),
            $updated->getPubkey(),
            $updated->getCreatedAt(),
            $newTags,
        ])));
        $updated->extractAndSetDTag();

        try {
            $em->persist($updated);
            $em->flush();
        } catch (\Throwable $e) {
            $logger->error('Failed to persist updated index event', [
                'slug' => $updated->getDTag(),
                'error' => $e->getMessage(),
            ]);
        }
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

