<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ArticleInPublicationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Tracks which kind 30040 publication events (magazines / reading-list categories)
 * directly include a specific article coordinate in their 'a' tags.
 *
 * Populated and maintained by ArticlePublicationIndexer whenever a kind 30040
 * event is ingested or replaced. Enables O(1) reverse-lookup: given an article,
 * which publications contain it?
 */
#[ORM\Entity(repositoryClass: ArticleInPublicationRepository::class)]
#[ORM\Table(name: 'article_in_publication')]
#[ORM\UniqueConstraint(name: 'uq_aip_article_container', columns: ['article_coordinate', 'container_pubkey', 'container_d_tag'])]
#[ORM\Index(name: 'idx_aip_article_coord', columns: ['article_coordinate'])]
#[ORM\Index(name: 'idx_aip_container', columns: ['container_pubkey', 'container_d_tag'])]
class ArticleInPublication
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    /**
     * Nostr coordinate of the article, e.g. "30023:hex-pubkey:slug"
     */
    #[ORM\Column(length: 512)]
    private string $articleCoordinate;

    /**
     * Pubkey of the kind 30040 event that directly lists this article.
     */
    #[ORM\Column(length: 64)]
    private string $containerPubkey;

    /**
     * d-tag (slug) of the kind 30040 event that directly lists this article.
     */
    #[ORM\Column(length: 512)]
    private string $containerDTag;

    /**
     * Cached title from the container event's 'title' tag (may be null for untitled lists).
     */
    #[ORM\Column(length: 512, nullable: true)]
    private ?string $containerTitle = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        string $articleCoordinate,
        string $containerPubkey,
        string $containerDTag,
        ?string $containerTitle = null,
    ) {
        $this->articleCoordinate = $articleCoordinate;
        $this->containerPubkey   = $containerPubkey;
        $this->containerDTag     = $containerDTag;
        $this->containerTitle    = $containerTitle;
        $this->updatedAt         = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getArticleCoordinate(): string
    {
        return $this->articleCoordinate;
    }

    public function getContainerPubkey(): string
    {
        return $this->containerPubkey;
    }

    public function getContainerDTag(): string
    {
        return $this->containerDTag;
    }

    public function getContainerTitle(): ?string
    {
        return $this->containerTitle;
    }

    public function setContainerTitle(?string $title): static
    {
        $this->containerTitle = $title;
        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touch(): static
    {
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }
}

