<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Event;
use App\Enum\KindsEnum;
use Doctrine\ORM\EntityManagerInterface;
use Elastica\Query;
use Elastica\Query\BoolQuery;
use Elastica\Query\Term;
use FOS\ElasticaBundle\Finder\FinderInterface;
use Psr\Cache\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use swentel\nostr\Key\Key;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Cache\CacheInterface;

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
    public function compose(): Response
    {
        return $this->render('reading_list/compose.html.twig');
    }

    /**
     *
     * @throws InvalidArgumentException
     */
    #[Route('/p/{pubkey}/list/{slug}', name: 'reading-list')]
    public function readingList($pubkey, $slug, CacheInterface $redisCache,
                                EntityManagerInterface $em,
                                FinderInterface $finder,
                                LoggerInterface $logger): Response
    {
        $key = 'single-reading-list-' . $pubkey . '-' . $slug;
        $logger->info(sprintf('Reading list: %s', $key));
        $list = $redisCache->get($key, function() use ($em, $pubkey, $slug) {
            // find reading list by pubkey+slug, kind 30040
            $lists = $em->getRepository(Event::class)->findBy(['pubkey' => $pubkey, 'kind' => KindsEnum::PUBLICATION_INDEX]);
            // filter by tag d = $slug
            $lists = array_filter($lists, function($ev) use ($slug) {
                return $ev->getSlug() === $slug;
            });
            // sort revisions and keep latest
            usort($lists, function($a, $b) {
                return $b->getCreatedAt() <=> $a->getCreatedAt();
            });
            return array_pop($lists);
        });

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
            $boolQuery = new BoolQuery();
            foreach ($coordinates as $coord) {
                $parts = explode(':', $coord, 3);
                [$kind, $author, $slug] = $parts;
                $termQuery = new BoolQuery();
                $termQuery->addMust(new Term(['kind' => (int)$kind]));
                $termQuery->addMust(new Term(['pubkey' => strtolower($author)]));
                $termQuery->addMust(new Term(['slug' => $slug]));
                $boolQuery->addShould($termQuery);
            }
            $finalQuery = new Query($boolQuery);
            $finalQuery->setSize(100); // Limit to 100 results
            $results = $finder->find($finalQuery);
            // Index results by their full coordinate for easy lookup
            foreach ($results as $result) {
                if ($result instanceof Event) {
                    $coordKey = sprintf('%d:%s:%s', $result->getKind(), strtolower($result->getPubkey()), $result->getSlug());
                    $articles[$coordKey] = $result;
                }
            }
        }

        return $this->render('pages/list.html.twig', [
            'list' => $list,
            'articles' => $articles,
        ]);
    }
}
