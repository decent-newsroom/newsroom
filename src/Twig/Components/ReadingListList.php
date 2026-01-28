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
     * @return array<int, array{title:string, slug:string, createdAt:int, pubkey:string, kind:int, type:string, itemCount:int}>
     */
    public function getLists(): array
    {
        $repo = $this->em->getRepository(Event::class);
        // Fetch reading lists (30040) and curation sets (30004, 30005, 30006)
        /** @var Event[] $events */
        $events = $repo->createQueryBuilder('e')
            ->where('e.kind IN (:kinds)')
            ->setParameter('kinds', [
                KindsEnum::PUBLICATION_INDEX->value,  // 30040
                KindsEnum::CURATION_SET->value,       // 30004
                KindsEnum::CURATION_VIDEOS->value,    // 30005
                KindsEnum::CURATION_PICTURES->value   // 30006
            ])
            ->orderBy('e.created_at', 'DESC')
            ->setMaxResults(200)
            ->getQuery()
            ->getResult();

        $out = [];
        $seen = [];
        foreach ($events as $ev) {
            $tags = $ev->getTags();
            $kind = $ev->getKind();
            $title = null;
            $slug = null;
            $itemCount = 0;
            $hasItems = false;

            // Determine type label from kind
            $typeLabel = match($kind) {
                30040 => 'Reading List',
                30004 => 'Articles/Notes',
                30005 => 'Videos',
                30006 => 'Pictures',
                default => 'Unknown',
            };

            foreach ($tags as $t) {
                if (!is_array($t)) continue;
                if (($t[0] ?? null) === 'title') { $title = (string)$t[1]; }
                if (($t[0] ?? null) === 'd') { $slug = (string)$t[1]; }
                // Count all 'a' tags (coordinate references)
                if (($t[0] ?? null) === 'a' && isset($t[1])) {
                    $itemCount++;
                    $hasItems = true;
                }
                // Count all 'e' tags (event ID references)
                if (($t[0] ?? null) === 'e' && isset($t[1])) {
                    $itemCount++;
                    $hasItems = true;
                }
            }

            // Require slug; skip malformed events without slug
            if (!$slug) continue;

            // Collapse the newest by slug
            if (isset($seen[$slug])) continue;
            $seen[$slug] = true;

            // For kind 30040 (reading lists), require long-form articles
            if ($kind === 30040) {
                $hasLongformArticles = false;
                foreach ($tags as $t) {
                    if (!is_array($t)) continue;
                    if (($t[0] ?? null) === 'a') {
                        // Split coordinate by colon and check first part
                        $coordParts = explode(':', $t[1] ?? '', 3);
                        if (count($coordParts) < 2) continue;
                        $coordKind = (int)$coordParts[0];
                        if ($coordKind == KindsEnum::LONGFORM->value) {
                            $hasLongformArticles = true;
                            break;
                        }
                    }
                }
                if (!$hasLongformArticles) continue;
            } else {
                // For curation sets (30004, 30005, 30006), require at least one item
                if (!$hasItems) continue;
            }

            $out[] = [
                'title' => $title ?: '(untitled)',
                'slug' => $slug,
                'createdAt' => $ev->getCreatedAt(),
                'pubkey' => $ev->getPubkey(),
                'kind' => $kind,
                'type' => $typeLabel,
                'itemCount' => $itemCount,
            ];
            if (count($out) >= $this->limit) break;
        }

        return $out;
    }
}
