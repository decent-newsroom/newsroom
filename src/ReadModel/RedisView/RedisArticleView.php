<?php

namespace App\ReadModel\RedisView;

/**
 * Redis view model for Article - MATCHES TEMPLATE EXPECTATIONS
 * Property names match what Twig templates expect from Article entities
 * This eliminates need for mapping layers
 */
final class RedisArticleView
{
    public function __construct(
        public string $id,
        public string $slug,                        // Template expects: article.slug
        public string $title,                       // Template expects: article.title
        public string $pubkey,                      // Template expects: article.pubkey
        public ?\DateTimeImmutable $createdAt = null, // Template expects: article.createdAt
        public ?string $summary = null,             // Template expects: article.summary
        public ?string $image = null,               // Template expects: article.image
        public ?string $eventId = null,             // Nostr event id
        public ?string $contentHtml = null,         // processedHtml for article detail pages
        public ?\DateTimeImmutable $publishedAt = null,
        public array $topics = [],                  // For topic filtering
    ) {}
}

