<?php

declare(strict_types=1);

namespace App\Service\EventWorkspace;

use App\Dto\EventWorkspace\EventPresentation;
use App\Entity\Article;
use App\Entity\Event;

final readonly class AuthoredArticlePreview
{
    public function __construct(private EventPresentationFactory $presentations) {}

    public function fromAuthorizedArticle(Article $article, ?Event $event): ?EventPresentation
    {
        $raw = $event === null ? $article->getRaw() : [
            'id' => $event->getId(),
            'pubkey' => $event->getPubkey(),
            'kind' => $event->getKind(),
            'created_at' => $event->getCreatedAt(),
            'tags' => $event->getTags(),
            'content' => $event->getContent(),
        ];
        if (($raw['id'] ?? null) !== $article->getEventId()
            || ($raw['pubkey'] ?? null) !== $article->getPubkey()
            || ($raw['kind'] ?? null) !== $article->getKind()?->value
            || !is_int($raw['created_at'] ?? null)
            || !is_array($raw['tags'] ?? null)
            || !is_string($raw['content'] ?? null)) {
            return null;
        }
        $presentation = $this->presentations->fromAuthorizedFields(
            $raw['id'], $raw['kind'], $raw['pubkey'], $raw['created_at'], $raw['tags'], $raw['content'],
        );

        return $presentation->coordinate === $article->getKind()?->value . ':' . $article->getPubkey() . ':' . $article->getSlug()
            ? $presentation : null;
    }
}
