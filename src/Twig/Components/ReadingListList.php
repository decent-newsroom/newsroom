<?php

namespace App\Twig\Components;

use App\Entity\Event;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent('Organisms:ReadingListList')]
final class ReadingListList
{
    public int $limit = 10;

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * @return array<int, array{title:string, slug:string, createdAt:int, pubkey:string}>
     */
    public function getLists(): array
    {
        $repo = $this->em->getRepository(Event::class);
        // Fetch more than we need to allow collapsing by slug
        /** @var Event[] $events */
        $events = $repo->findBy(['kind' => 30040], ['created_at' => 'DESC'], 200);

        $out = [];
        $seen = [];
        foreach ($events as $ev) {
            $tags = $ev->getTags();
            $isReadingList = false;
            $title = null; $slug = null;
            foreach ($tags as $t) {
                if (!is_array($t)) continue;
                if (($t[0] ?? null) === 'type' && ($t[1] ?? null) === 'reading-list') { $isReadingList = true; }
                if (($t[0] ?? null) === 'title') { $title = (string)$t[1]; }
                if (($t[0] ?? null) === 'd') { $slug = (string)$t[1]; }
            }
            if (!$isReadingList) continue;
            // Require slug; skip malformed events without slug
            if (!$slug) continue;

            // Collapse newest by slug
            if (isset($seen[$slug])) continue;
            $seen[$slug] = true;

            $out[] = [
                'title' => $title ?: '(untitled)',
                'slug' => $slug,
                'createdAt' => $ev->getCreatedAt(),
                'pubkey' => $ev->getPubkey(),
            ];
            if (count($out) >= $this->limit) break;
        }

        return $out;
    }
}
