<?php

declare(strict_types=1);

namespace App\ChatBundle\Entity;

use App\ChatBundle\Repository\ChatPushSubscriptionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Stores a Web Push API subscription for a ChatUser device/browser.
 * One user can have multiple subscriptions (multiple browsers/devices).
 */
#[ORM\Entity(repositoryClass: ChatPushSubscriptionRepository::class)]
#[ORM\Table(name: 'chat_push_subscription')]
#[ORM\UniqueConstraint(name: 'chat_push_endpoint_unique', columns: ['endpoint'])]
class ChatPushSubscription
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ChatUser::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ChatUser $chatUser;

    /** Push service endpoint URL (e.g. https://fcm.googleapis.com/fcm/send/...) */
    #[ORM\Column(type: Types::TEXT)]
    private string $endpoint;

    /** p256dh public key from the browser PushSubscription */
    #[ORM\Column(length: 255)]
    private string $publicKey;

    /** Auth secret from the browser PushSubscription */
    #[ORM\Column(length: 255)]
    private string $authToken;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /** Optional expiration from PushSubscription.expirationTime */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getChatUser(): ChatUser
    {
        return $this->chatUser;
    }

    public function setChatUser(ChatUser $chatUser): self
    {
        $this->chatUser = $chatUser;
        return $this;
    }

    public function getEndpoint(): string
    {
        return $this->endpoint;
    }

    public function setEndpoint(string $endpoint): self
    {
        $this->endpoint = $endpoint;
        return $this;
    }

    public function getPublicKey(): string
    {
        return $this->publicKey;
    }

    public function setPublicKey(string $publicKey): self
    {
        $this->publicKey = $publicKey;
        return $this;
    }

    public function getAuthToken(): string
    {
        return $this->authToken;
    }

    public function setAuthToken(string $authToken): self
    {
        $this->authToken = $authToken;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(?\DateTimeImmutable $expiresAt): self
    {
        $this->expiresAt = $expiresAt;
        return $this;
    }
}

