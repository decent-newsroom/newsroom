<?php

namespace App\Twig\Components\Molecules;

use App\Entity\Event;
use App\Enum\KindsEnum;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent]
final class CategoryLink
{
    public string $title;
    public string $slug;
    public int $kind = 0;
    public ?string $mag = null; // magazine slug passed from parent (optional)

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function mount($coordinate): void
    {
        if (key_exists(1, $coordinate)) {
            $parts = explode(':', $coordinate[1], 3);
            // Expect format kind:pubkey:slug
            $this->kind = (int)($parts[0] ?? 0);
            $this->slug = $parts[2] ?? '';

            // Query the database for the event by slug and kind
            $sql = "SELECT e.* FROM event e
                    WHERE e.tags::jsonb @> ?::jsonb
                    AND e.kind = ?
                    ORDER BY e.created_at DESC
                    LIMIT 1";

            $conn = $this->entityManager->getConnection();
            $result = $conn->executeQuery($sql, [
                json_encode([['d', $this->slug]]),
                $this->kind
            ]);

            $eventData = $result->fetchAssociative();

            if ($eventData === false) {
                $this->title = $this->slug ?: 'Item';
                return;
            }

            $tags = json_decode($eventData['tags'], true);

            $title = array_filter($tags, function($tag) {
                return ($tag[0] === 'title');
            });

            $this->title = $title[array_key_first($title)][1] ?? ($this->slug ?: 'Item');
        } else {
            $this->title = 'Item';
            $this->slug = '';
        }
    }

    public function isCategory(): bool
    {
        return $this->kind === KindsEnum::PUBLICATION_INDEX->value;
    }

    public function isChapter(): bool
    {
        return $this->kind === KindsEnum::PUBLICATION_CONTENT->value;
    }
}
