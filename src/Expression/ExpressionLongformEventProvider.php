<?php

declare(strict_types=1);

namespace App\Expression;

use DecentNewsroom\ExpressionBundle\Contract\EventInterface;
use DecentNewsroom\ExpressionBundle\Contract\LongformEventProviderInterface;
use DecentNewsroom\ExpressionBundle\Model\ArrayEvent;
use App\Repository\ArticleRepository;

final class ExpressionLongformEventProvider implements LongformEventProviderInterface
{
    public function __construct(
        private readonly ArticleRepository $repository,
    ) {}

    public function findByEventId(string $eventId): ?EventInterface
    {
        $article = $this->repository->findOneBy(['eventId' => $eventId]);

        return $article !== null ? $this->toEvent($article) : null;
    }

    public function findByPubkeyAndSlugs(string $pubkey, array $slugs): array
    {
        $articles = $this->repository->findBy([
            'pubkey' => $pubkey,
            'slug' => $slugs,
        ]);

        return array_map(fn ($article): EventInterface => $this->toEvent($article), $articles);
    }

    private function toEvent(object $article): EventInterface
    {
        $tags = [];
        $raw = $article->getRaw();
        if (is_array($raw) && is_array($raw['tags'] ?? null)) {
            foreach ($raw['tags'] as $tag) {
                if (is_array($tag)) {
                    $tags[] = array_values(array_map(
                        static fn (mixed $value): string => is_scalar($value) ? (string) $value : '',
                        $tag,
                    ));
                }
            }
        }

        if ($tags === []) {
            if ($article->getSlug() !== null) {
                $tags[] = ['d', $article->getSlug()];
            }
            foreach ([
                'title' => $article->getTitle(),
                'published_at' => $article->getPublishedAt()?->getTimestamp(),
            ] as $name => $value) {
                if ($value !== null) {
                    $tags[] = [$name, (string) $value];
                }
            }
            foreach ($article->getTopics() ?? [] as $topic) {
                if (is_string($topic) && $topic !== '') {
                    $tags[] = ['t', $topic];
                }
            }
        }

        return new ArrayEvent(
            id: $article->getEventId() ?? '',
            kind: $article->getKind()?->value ?? 30023,
            pubkey: $article->getPubkey() ?? '',
            content: $article->getContent() ?? '',
            createdAt: $article->getCreatedAt()?->getTimestamp() ?? 0,
            tags: $tags,
            sig: $article->getSig() ?? '',
        );
    }
}
