<?php

namespace App\Twig\Components;

use App\Entity\Event;
use App\Enum\KindsEnum;
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
            $hasArticles = false;
            $tags = $ev->getTags();
            $title = null; $slug = null;
            foreach ($tags as $t) {
                if (!is_array($t)) continue;
                if (($t[0] ?? null) === 'title') { $title = (string)$t[1]; }
                if (($t[0] ?? null) === 'd') { $slug = (string)$t[1]; }
            }
            // Require slug; skip malformed events without slug
            if (!$slug) continue;

            // Collapse the newest by slug
            if (isset($seen[$slug])) continue;
            $seen[$slug] = true;

            // Make sure the 'a' tags contain long form articles
            foreach ($tags as $t) {
                if (!is_array($t)) continue;
                if (($t[0] ?? null) === 'a') {
                    // Split coordinate by colon and check first part
                    $coordParts = explode(':', $t[1] ?? '', 3);
                    if (count($coordParts) < 2) continue;
                    $kind = (int)$coordParts[0];
                    if ($kind == KindsEnum::LONGFORM->value) {
                        $hasArticles = true;
                        break;
                    }
                }
            }

            if (!$hasArticles) continue;

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
