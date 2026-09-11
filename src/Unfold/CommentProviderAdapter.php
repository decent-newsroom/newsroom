<?php

declare(strict_types=1);

namespace App\Unfold;

use App\Repository\EventRepository;
use DecentNewsroom\UnfoldBundle\Contract\Comment;
use DecentNewsroom\UnfoldBundle\Contract\CommentProviderInterface;

final readonly class CommentProviderAdapter implements CommentProviderInterface
{
    public function __construct(private EventRepository $repository)
    {
    }

    public function findByCoordinate(string $coordinate): array
    {
        $comments = [];
        foreach ($this->repository->findCommentsByCoordinate($coordinate) as $event) {
            $comments[] = new Comment(
                id: (string) $event->getId(),
                kind: (int) $event->getKind(),
                pubkey: (string) $event->getPubkey(),
                content: (string) $event->getContent(),
                createdAt: (int) $event->getCreatedAt(),
                tags: $this->tags($event->getTags()),
            );
        }
        return $comments;
    }

    /** @return list<list<string>> */
    private function tags(mixed $tags): array
    {
        if (!is_array($tags)) {
            return [];
        }
        $result = [];
        foreach ($tags as $tag) {
            if (!is_array($tag) || !array_reduce($tag, static fn(bool $valid, mixed $value): bool => $valid && is_scalar($value), true)) {
                continue;
            }
            $result[] = array_map(static fn(mixed $value): string => (string) $value, $tag);
        }
        return $result;
    }
}
