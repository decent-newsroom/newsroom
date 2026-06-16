<?php

namespace App\Twig\Components\Molecules;

use App\Enum\KindsEnum;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent]
final class CategoryLink
{
    /** @var array<string, string> */
    private static array $titleCache = [];

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
            $pubkey = (string) ($parts[1] ?? '');
            $this->slug = $parts[2] ?? '';

            if ($this->kind <= 0 || $pubkey === '' || $this->slug === '') {
                $this->title = $this->slug ?: 'Item';
                return;
            }

            $cacheKey = $this->kind . ':' . $pubkey . ':' . $this->slug;
            if (isset(self::$titleCache[$cacheKey])) {
                $this->title = self::$titleCache[$cacheKey];
                return;
            }

            $conn = $this->entityManager->getConnection();
            // Fast path: indexed replaceable lookup.
            $eventData = $conn->executeQuery(
                'SELECT e.tags FROM event e
                 WHERE e.kind = :kind
                   AND e.pubkey = :pubkey
                   AND e.d_tag = :slug
                 ORDER BY e.created_at DESC
                 LIMIT 1',
                [
                    'kind' => $this->kind,
                    'pubkey' => $pubkey,
                    'slug' => $this->slug,
                ]
            )->fetchAssociative();

            if ($eventData === false) {
                // Backward compatibility for rows without d_tag backfill.
                $eventData = $conn->executeQuery(
                    "SELECT e.tags FROM event e
                     WHERE e.kind = :kind
                       AND e.pubkey = :pubkey
                       AND EXISTS (
                         SELECT 1 FROM jsonb_array_elements(e.tags) AS tag
                         WHERE tag->>0 = 'd' AND tag->>1 = :slug
                       )
                     ORDER BY e.created_at DESC
                     LIMIT 1",
                    [
                        'kind' => $this->kind,
                        'pubkey' => $pubkey,
                        'slug' => $this->slug,
                    ]
                )->fetchAssociative();
            }

            if ($eventData === false) {
                $this->title = $this->slug ?: 'Item';
                self::$titleCache[$cacheKey] = $this->title;
                return;
            }

            $tags = json_decode($eventData['tags'] ?? '[]', true);

            $resolvedTitle = $this->slug ?: 'Item';
            if (is_array($tags)) {
                foreach ($tags as $tag) {
                    if (is_array($tag) && ($tag[0] ?? null) === 'title' && isset($tag[1]) && is_string($tag[1]) && $tag[1] !== '') {
                        $resolvedTitle = $tag[1];
                        break;
                    }
                }
            }

            $this->title = $resolvedTitle;
            self::$titleCache[$cacheKey] = $this->title;
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
