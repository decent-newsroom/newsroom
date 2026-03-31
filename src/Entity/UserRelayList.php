<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserRelayListRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Cached relay list for a user (NIP-65 kind 10002 data).
 *
 * This is NOT a Nostr event — it's a server-side cache of the parsed relay
 * list discovered from the network or received via relay subscriptions.
 * One row per pubkey, upserted when newer data arrives.
 *
 * Previously this data was stored as synthetic "events" in the event table
 * with fake IDs (relay_list_{hex}_{timestamp}) and empty signatures. That
 * polluted the event table with non-Nostr data and created a hidden
 * assumption that could break if event verification was ever added.
 */
#[ORM\Entity(repositoryClass: UserRelayListRepository::class)]
#[ORM\Table(name: 'user_relay_list')]
#[ORM\Index(columns: ['pubkey'], name: 'idx_user_relay_list_pubkey')]
class UserRelayList
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    /**
     * Hex pubkey of the user who owns this relay list.
     */
    #[ORM\Column(length: 64, unique: true)]
    private string $pubkey = '';

    /**
     * Relay URLs the user reads from.
     *
     * @var string[]
     */
    #[ORM\Column(type: 'jsonb')]
    private array $readRelays = [];

    /**
     * Relay URLs the user writes to.
     *
     * @var string[]
     */
    #[ORM\Column(type: 'jsonb')]
    private array $writeRelays = [];

    /**
     * Nostr created_at timestamp from the original kind 10002 event.
     * Used for "only update if newer" logic.
     */
    #[ORM\Column(type: Types::BIGINT)]
    private int $createdAt = 0;

    /**
     * Server timestamp of the last upsert.
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPubkey(): string
    {
        return $this->pubkey;
    }

    public function setPubkey(string $pubkey): static
    {
        $this->pubkey = $pubkey;
        return $this;
    }

    /** @return string[] */
    public function getReadRelays(): array
    {
        return $this->readRelays;
    }

    /** @param string[] $readRelays */
    public function setReadRelays(array $readRelays): static
    {
        $this->readRelays = array_values($readRelays);
        return $this;
    }

    /** @return string[] */
    public function getWriteRelays(): array
    {
        return $this->writeRelays;
    }

    /** @param string[] $writeRelays */
    public function setWriteRelays(array $writeRelays): static
    {
        $this->writeRelays = array_values($writeRelays);
        return $this;
    }

    /**
     * All unique relay URLs (read + write merged).
     *
     * @return string[]
     */
    public function getAllRelays(): array
    {
        return array_values(array_unique(array_merge($this->readRelays, $this->writeRelays)));
    }

    public function getCreatedAt(): int
    {
        return $this->createdAt;
    }

    public function setCreatedAt(int $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    /**
     * Touch the updated_at timestamp (call before flush on upsert).
     */
    public function touch(): static
    {
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }
}

