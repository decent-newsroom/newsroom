<?php

namespace App\Entity;

use App\Repository\EventRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use swentel\nostr\Nip19\Nip19Helper;

/**
 * Nostr events
 */
#[ORM\Entity(repositoryClass: EventRepository::class)]
class Event
{
    #[ORM\Id]
    #[ORM\Column(length: 225)]
    private string $id;
    #[ORM\Column(length: 225, nullable: true)]
    private ?string $eventId = null;
    #[ORM\Column(type: Types::INTEGER)]
    private int $kind = 0;
    #[ORM\Column(length: 255)]
    private string $pubkey = '';
    #[ORM\Column(type: Types::TEXT)]
    private string $content = '';
    #[ORM\Column(type: Types::BIGINT)]
    private int $created_at = 0;
    #[ORM\Column(type: Types::JSON)]
    private array $tags = [];
    #[ORM\Column(length: 255)]
    private string $sig = '';

    public function getId(): string
    {
        return $this->id;
    }

    public function setId(string $id): void
    {
        $this->id = $id;
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

    public function getKind(): int
    {
        return $this->kind;
    }

    public function setKind(int $kind): void
    {
        $this->kind = $kind;
    }

    public function getPubkey(): string
    {
        return $this->pubkey;
    }

    public function setPubkey(string $pubkey): void
    {
        $this->pubkey = $pubkey;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function setContent(string $content): void
    {
        $this->content = $content;
    }

    public function getCreatedAt(): int
    {
        return $this->created_at;
    }

    public function setCreatedAt(int $created_at): void
    {
        $this->created_at = $created_at;
    }

    public function getTags(): array
    {
        return $this->tags;
    }

    public function setTags(array $tags): void
    {
        $this->tags = $tags;
    }

    public function getSig(): string
    {
        return $this->sig;
    }

    public function setSig(string $sig): void
    {
        $this->sig = $sig;
    }


    public function getTitle(): ?string
    {
        foreach ($this->getTags() as $tag) {
            if ($tag[0] === 'title') {
                return $tag[1];
            }
        }
        return null;
    }

    public function getSummary(): ?string
    {
        foreach ($this->getTags() as $tag) {
            if ($tag[0] === 'summary') {
                return $tag[1];
            }
        }
        return null;
    }

    public function getLanguage(): ?string
    {
        // First check for simple 'language' tag (non-standard but may exist)
        foreach ($this->getTags() as $tag) {
            if ($tag[0] === 'language' && isset($tag[1])) {
                return $tag[1];
            }
        }

        // Check for NIP-32 labeled language (ISO-639-1)
        // Look for 'l' tag with 'ISO-639-1' namespace
        foreach ($this->getTags() as $tag) {
            if ($tag[0] === 'l' && isset($tag[1]) && isset($tag[2]) && $tag[2] === 'ISO-639-1') {
                return $tag[1];
            }
        }

        return null;
    }

    public function getSlug(): ?string
    {
        foreach ($this->getTags() as $tag) {
            if (key_exists(0, $tag) && $tag[0] === 'd') {
                return $tag[1];
            }
        }

        return null;
    }

    public function addTag(array $tag): static
    {
        $this->tags[] = $tag;

        return $this;
    }

    /**
     * Get media URL from event tags
     * Looks for 'url' or 'image' tags commonly used in NIP-68 (kind 20, 21, 22)
     */
    public function getMediaUrl(): ?string
    {
        foreach ($this->getTags() as $tag) {
            if (!is_array($tag) || count($tag) < 2) {
                continue;
            }

            // Check for url tag (most common for media events)
            if ($tag[0] === 'url') {
                return $tag[1];
            }

            // Check for image tag (alternative)
            if ($tag[0] === 'image') {
                return $tag[1];
            }
        }

        // Fallback: check if content is a URL
        if (!empty($this->content) && filter_var($this->content, FILTER_VALIDATE_URL)) {
            return $this->content;
        }

        return null;
    }

    /**
     * Get image URL from event tags (alias for getMediaUrl for template compatibility)
     */
    public function getImage(): ?string
    {
        return $this->getMediaUrl();
    }

    /**
     * Check if event is marked as NSFW
     * Checks for content-warning tags and NSFW-related hashtags (NIP-32)
     */
    public function isNSFW(): bool
    {
        if (!is_array($this->tags)) {
            return false;
        }

        foreach ($this->tags as $tag) {
            if (!is_array($tag) || count($tag) < 1) {
                continue;
            }

            // Check for content-warning tag (NIP-32)
            if ($tag[0] === 'content-warning') {
                return true;
            }

            // Check for L tag with NSFW marking
            if ($tag[0] === 'L' && count($tag) >= 2 && strtolower($tag[1]) === 'nsfw') {
                return true;
            }

            // Check for hashtags that indicate NSFW content
            if ($tag[0] === 't' && count($tag) >= 2) {
                $hashtag = strtolower($tag[1]);
                if (in_array($hashtag, ['nsfw', 'adult', 'explicit', '18+', 'nsfl'])) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Encode event ID as note1... format (NIP-19)
     */
    public function encodeAsNote1(): string
    {
        $nip19 = new Nip19Helper();
        return $nip19->encodeNote($this->id);
    }
}
