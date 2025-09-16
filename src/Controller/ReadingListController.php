<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Event;
use Doctrine\ORM\EntityManagerInterface;
use swentel\nostr\Key\Key;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
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
        if ($user && method_exists($user, 'getUserIdentifier')) {
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
                    ];
                }
            }
        }

        return $this->render('reading_list/index.html.twig', [
            'lists' => $lists,
        ]);
    }

    #[Route('/reading-list/compose', name: 'reading_list_compose')]
    public function compose(): Response
    {
        return $this->render('reading_list/compose.html.twig');
    }
}
