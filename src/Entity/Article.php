<?php

namespace App\Entity;

use App\Enum\EventStatusEnum;
use App\Enum\IndexStatusEnum;
use App\Enum\KindsEnum;
use App\Repository\ArticleRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Entity storing long-form articles
 * Needed beyond the Event entity, because of the local functionalities built on top of the original events
 * - editor
 * - indexing and search
 * NIP-23, kinds 30023, 30024
 */
#[ORM\Entity(repositoryClass: ArticleRepository::class)]
class Article
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(length: 225)]
    private ?int $id = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private $raw = null;

    #[ORM\Column(length: 225, nullable: true)]
    private ?string $eventId = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $slug = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $content = null;

    #[ORM\Column(nullable: true, enumType: KindsEnum::class)]
    private ?KindsEnum $kind = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $title = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $summary = null;

    #[ORM\Column(length: 255)]
    private ?string $pubkey = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(length: 255)]
    private ?string $sig = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $image = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private $topics = null;

    #[ORM\Column(nullable: true, enumType: EventStatusEnum::class)]
    private ?EventStatusEnum $eventStatus = EventStatusEnum::PREVIEW;

    #[ORM\Column(nullable: true, enumType: IndexStatusEnum::class)]
    private ?IndexStatusEnum $indexStatus = IndexStatusEnum::NOT_INDEXED;

    // Local properties
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $currentPlaces;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $ratingNegative = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $ratingPositive = null;

    public function getId(): null|int|string
    {
        return $this->id;
    }

    public function setId(int|string $id): static
    {
        $this->id = $id;

        return $this;
    }

    public function getEventId(): ?string
    {
        return $this->eventId;
    }

    public function setEventId(string $eventId): static
    {
        $this->eventId = $eventId;

        return $this;
    }

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function setSlug(?string $slug): static
    {
        $this->slug = $slug;

        return $this;
    }

    public function getContent(): ?string
    {
        return $this->content;
    }

    public function setContent(?string $content): static
    {
        $this->content = $content;

        return $this;
    }

    public function getKind(): ?KindsEnum
    {
        return $this->kind;
    }

    public function setKind(null|KindsEnum|int $kind): static
    {
        if (is_int($kind)) {
            $kind = KindsEnum::tryFrom($kind);
            if ($kind === null) {
                throw new \InvalidArgumentException("Invalid kind value: $kind");
            }
        }
        $this->kind = $kind;

        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getSummary(): ?string
    {
        return $this->summary;
    }

    public function setSummary(?string $summary): static
    {
        $this->summary = $summary;

        return $this;
    }

    public function getPubkey(): ?string
    {
        return $this->pubkey;
    }

    public function setPubkey(string $pubkey): static
    {
        $this->pubkey = $pubkey;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable|int $createdAt): static
    {
        if (is_int($createdAt)) {
            $createdAt = (new \DateTimeImmutable())->setTimestamp($createdAt);
        }
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getSig(): ?string
    {
        return $this->sig;
    }

    public function setSig(string $sig): static
    {
        $this->sig = $sig;

        return $this;
    }

    public function getImage(): ?string
    {
        return $this->image;
    }

    public function setImage(?string $image): static
    {
        $this->image = $image;

        return $this;
    }

    public function getPublishedAt(): ?\DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function setPublishedAt(\DateTimeImmutable $publishedAt): static
    {
        $this->publishedAt = $publishedAt;

        return $this;
    }

    public function getTopics()
    {
        return $this->topics;
    }

    public function setTopics($topics)
    {
        $this->topics = $topics;

        return $this;
    }

    public function addTopic(string $topic): static
    {
        // remove # and lowercase topic
        $topic = strtolower(str_replace('#', '', $topic));
        // check if topic already exists
        if (in_array($topic, $this->topics ?? [])) {
            return $this;
        }
        $this->topics[] = $topic;

        return $this;
    }

    public function clearTopics(): static
    {
        $this->topics = [];
        return $this;
    }

    public function getEventStatus(): ?EventStatusEnum
    {
        return $this->eventStatus;
    }

    public function setEventStatus(?EventStatusEnum $eventStatus): static
    {
        $this->eventStatus = $eventStatus;

        return $this;
    }

    public function getIndexStatus(): ?IndexStatusEnum
    {
        return $this->indexStatus;
    }

    public function setIndexStatus(?IndexStatusEnum $indexStatus): static
    {
        $this->indexStatus = $indexStatus;

        return $this;
    }

    public function getCurrentPlaces(): ?array
    {
        return $this->currentPlaces;
    }

    public function setCurrentPlaces(array $currentPlaces): static
    {
        $this->currentPlaces = $currentPlaces;

        return $this;
    }

    public function getRatingNegative(): ?int
    {
        return $this->ratingNegative;
    }

    public function setRatingNegative(?int $ratingNegative): static
    {
        $this->ratingNegative = $ratingNegative;

        return $this;
    }

    public function getRatingPositive(): ?int
    {
        return $this->ratingPositive;
    }

    public function setRatingPositive(?int $ratingPositive): static
    {
        $this->ratingPositive = $ratingPositive;

        return $this;
    }

    public function isDraft()
    {
        return $this->eventStatus === EventStatusEnum::PREVIEW;
    }

    public function getRaw()
    {
        return $this->raw;
    }

    public function setRaw($raw): void
    {
        $this->raw = $raw;
    }
}
