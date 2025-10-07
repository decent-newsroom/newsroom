<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Article;
use App\Entity\Event;
use App\Enum\KindsEnum;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use swentel\nostr\Key\Key;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class ReadingListController extends AbstractController
{
    #[Route('/reading-list', name: 'reading_list_index')]
    public function index(EntityManagerInterface $em): Response
    {
        $lists = [];
        $user = $this->getUser();
        $pubkeyHex = null;
        if ($user) {
            try {
                $key = new Key();
                $pubkeyHex = $key->convertToHex($user->getUserIdentifier());
            } catch (\Throwable $e) {
                $pubkeyHex = null;
            }
        }

        if ($pubkeyHex) {
            $repo = $em->getRepository(Event::class);
            $events = $repo->findBy(['kind' => 30040, 'pubkey' => $pubkeyHex], ['created_at' => 'DESC']);
            $seenSlugs = [];
            foreach ($events as $ev) {
                if (!$ev instanceof Event) continue;
                $tags = $ev->getTags();
                $isReadingList = false;
                $title = null; $slug = null; $summary = null;
                foreach ($tags as $t) {
                    if (is_array($t)) {
                        if (($t[0] ?? null) === 'type' && ($t[1] ?? null) === 'reading-list') { $isReadingList = true; }
                        if (($t[0] ?? null) === 'title') { $title = (string)$t[1]; }
                        if (($t[0] ?? null) === 'summary') { $summary = (string)$t[1]; }
                        if (($t[0] ?? null) === 'd') { $slug = (string)$t[1]; }
                    }
                }
                if ($isReadingList) {
                    // Collapse by slug: keep only newest per slug
                    $keySlug = $slug ?: ('__no_slug__:' . $ev->getId());
                    if (isset($seenSlugs[$slug ?? $keySlug])) {
                        continue;
                    }
                    $seenSlugs[$slug ?? $keySlug] = true;

                    $lists[] = [
                        'id' => $ev->getId(),
                        'title' => $title ?: '(untitled)',
                        'summary' => $summary,
                        'slug' => $slug,
                        'createdAt' => $ev->getCreatedAt(),
                        'pubkey' => $ev->getPubkey(),
                    ];
                }
            }
        }

        return $this->render('reading_list/index.html.twig', [
            'lists' => $lists,
        ]);
    }

    #[Route('/reading-list/compose', name: 'reading_list_compose')]
    public function compose(Request $request, EntityManagerInterface $em): Response
    {
        // Check if a coordinate was passed via URL parameter
        $coordinate = $request->query->get('add');
        $addedArticle = null;

        if ($coordinate) {
            // Auto-add the coordinate to the current draft
            $session = $request->getSession();
            $draft = $session->get('read_wizard');

            if (!$draft instanceof \App\Dto\CategoryDraft) {
                $draft = new \App\Dto\CategoryDraft();
                $draft->title = 'My Reading List';
                $draft->slug = substr(bin2hex(random_bytes(6)), 0, 8);
            }

            if (!in_array($coordinate, $draft->articles, true)) {
                $draft->articles[] = $coordinate;
                $session->set('read_wizard', $draft);
                $addedArticle = $coordinate;
            }
        }

        return $this->render('reading_list/compose.html.twig', [
            'addedArticle' => $addedArticle,
        ]);
    }

    /**
     *
     */
    #[Route('/p/{pubkey}/list/{slug}', name: 'reading-list')]
    public function readingList($pubkey, $slug,
                                EntityManagerInterface $em,
                                LoggerInterface $logger): Response
    {
        $logger->info(sprintf('Reading list: pubkey=%s, slug=%s', $pubkey, $slug));

        // Find reading list by pubkey+slug, kind 30040 directly from database
        $repo = $em->getRepository(Event::class);
        $lists = $repo->findBy(['pubkey' => $pubkey, 'kind' => KindsEnum::PUBLICATION_INDEX], ['created_at' => 'DESC']);
        // Filter by slug
        $list = null;
        foreach ($lists as $ev) {
            if (!$ev instanceof Event) continue;

            $eventSlug = $ev->getSlug();

            if ($eventSlug === $slug) {
                $list = $ev;
                break; // Found the latest one
            }
        }

        if (!$list) {
            throw $this->createNotFoundException('Reading list not found');
        }

        // fetch articles listed in the list's a tags
        $coordinates = []; // Store full coordinates (kind:author:slug)
        // Extract category metadata and article coordinates
        foreach ($list->getTags() as $tag) {
            if ($tag[0] === 'a') {
                $coordinates[] = $tag[1]; // Store the full coordinate
            }
        }

        $articles = [];
        if (count($coordinates) > 0) {
            $articleRepo = $em->getRepository(Article::class);

            // Query database directly for each coordinate
            foreach ($coordinates as $coord) {
                $parts = explode(':', $coord, 3);
                if (count($parts) === 3) {
                    [$kind, $author, $articleSlug] = $parts;

                    // Find the most recent event matching this coordinate
                    $events = $articleRepo->findBy([
                        'slug' => $articleSlug,
                        'pubkey' => $author
                    ], ['createdAt' => 'DESC']);

                    // Filter by slug and get the latest
                    foreach ($events as $event) {
                        if ($event->getSlug() === $articleSlug) {
                            $articles[] = $event;
                            break; // Take the first match (most recent if ordered)
                        }
                    }
                }
            }
        }

        return $this->render('pages/list.html.twig', [
            'list' => $list,
            'articles' => $articles,
        ]);
    }
}
