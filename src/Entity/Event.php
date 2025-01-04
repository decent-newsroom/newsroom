<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Nostr events
 */
#[ORM\Entity]
class Event
{
    #[ORM\Id]
    #[ORM\Column(length: 225)]
    private string $id;
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
            if (array_key_first($tag) === 'title') {
                return $tag['title'];
            }
        }
        return null;
    }

    public function getSlug(): ?string
    {
        foreach ($this->getTags() as $tag) {
            if (array_key_first($tag) === 'd') {
                return $tag['d'];
            }
        }

        return null;
    }
}
