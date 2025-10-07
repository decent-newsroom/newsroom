<?php

namespace App\Twig\Components\Molecules;

use App\Entity\Event;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent]
final class CategoryLink
{
    public string $title;
    public string $slug;
    public ?string $mag = null; // magazine slug passed from parent (optional)

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function mount($coordinate): void
    {
        if (key_exists(1, $coordinate)) {
            $parts = explode(':', $coordinate[1], 3);
            // Expect format kind:pubkey:slug
            $this->slug = $parts[2] ?? '';

            // Query the database for the category event by slug using native SQL
            $sql = "SELECT e.* FROM event e
                    WHERE e.tags::jsonb @> ?::jsonb
                    LIMIT 1";

            $conn = $this->entityManager->getConnection();
            $result = $conn->executeQuery($sql, [
                json_encode([['d', $this->slug]])
            ]);

            $eventData = $result->fetchAssociative();

            if ($eventData === false) {
                $this->title = $this->slug ?: 'Category';
                return;
            }

            $tags = json_decode($eventData['tags'], true);

            $title = array_filter($tags, function($tag) {
                return ($tag[0] === 'title');
            });

            $this->title = $title[array_key_first($title)][1] ?? ($this->slug ?: 'Category');
        } else {
            $this->title = 'Category';
            $this->slug = '';
        }

    }

}
