<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\RelayInformationRepository;
use App\Util\RelayUrlNormalizer;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Cached NIP-11 Relay Information Document.
 *
 * One row per known relay URL, keyed by the canonical normalized URL
 * (lowercase host, no trailing slash). Refreshed periodically by
 * {@see \App\Service\Nostr\RelayInformationFetcher} via HTTP GET with
 * `Accept: application/nostr+json`.
 *
 * `auth_required` is denormalised out of `limitation` so the gateway
 * preflight check can do a single indexed lookup before opening a
 * WebSocket.
 */
#[ORM\Entity(repositoryClass: RelayInformationRepository::class)]
#[ORM\Table(name: 'relay_information')]
#[ORM\Index(name: 'idx_relay_information_fetched_at', columns: ['fetched_at'])]
#[ORM\Index(name: 'idx_relay_information_auth_required', columns: ['auth_required'])]
class RelayInformation
{
    #[ORM\Id]
    #[ORM\Column(name: 'url', length: 255)]
    private string $url;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $name = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $pubkey = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $contact = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $software = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $version = null;

    /** @var int[] */
    #[ORM\Column(type: Types::JSON, options: ['default' => '[]'])]
    private array $supportedNips = [];

    /** @var array<string,mixed>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $limitation = null;

    /** @var string[]|null */
    #[ORM\Column(name: 'relay_countries', type: Types::JSON, nullable: true)]
    private ?array $relayCountries = null;

    /** @var string[]|null */
    #[ORM\Column(name: 'language_tags', type: Types::JSON, nullable: true)]
    private ?array $languageTags = null;

    /** @var string[]|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $tags = null;

    #[ORM\Column(name: 'posting_policy', length: 512, nullable: true)]
    private ?string $postingPolicy = null;

    #[ORM\Column(name: 'payments_url', length: 512, nullable: true)]
    private ?string $paymentsUrl = null;

    #[ORM\Column(length: 512, nullable: true)]
    private ?string $icon = null;

    /** @var array<string,mixed>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $fees = null;

    #[ORM\Column(name: 'auth_required', type: Types::BOOLEAN, options: ['default' => false])]
    private bool $authRequired = false;

    #[ORM\Column(name: 'fetched_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $fetchedAt = null;

    #[ORM\Column(name: 'fetch_error', type: Types::TEXT, nullable: true)]
    private ?string $fetchError = null;

    #[ORM\Column(name: 'fetch_attempts', type: Types::INTEGER, options: ['default' => 0])]
    private int $fetchAttempts = 0;

    public function __construct(string $url)
    {
        $this->url = RelayUrlNormalizer::normalize($url);
    }

    public function getUrl(): string { return $this->url; }

    public function getName(): ?string { return $this->name; }
    public function setName(?string $v): self { $this->name = $v; return $this; }

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $v): self { $this->description = $v; return $this; }

    public function getPubkey(): ?string { return $this->pubkey; }
    public function setPubkey(?string $v): self { $this->pubkey = $v; return $this; }

    public function getContact(): ?string { return $this->contact; }
    public function setContact(?string $v): self { $this->contact = $v; return $this; }

    public function getSoftware(): ?string { return $this->software; }
    public function setSoftware(?string $v): self { $this->software = $v; return $this; }

    public function getVersion(): ?string { return $this->version; }
    public function setVersion(?string $v): self { $this->version = $v; return $this; }

    /** @return int[] */
    public function getSupportedNips(): array { return $this->supportedNips; }
    /** @param int[] $v */
    public function setSupportedNips(array $v): self { $this->supportedNips = array_values(array_unique(array_map('intval', $v))); return $this; }

    /** @return array<string,mixed>|null */
    public function getLimitation(): ?array { return $this->limitation; }
    /** @param array<string,mixed>|null $v */
    public function setLimitation(?array $v): self { $this->limitation = $v; return $this; }

    /** @return string[]|null */
    public function getRelayCountries(): ?array { return $this->relayCountries; }
    /** @param string[]|null $v */
    public function setRelayCountries(?array $v): self { $this->relayCountries = $v; return $this; }

    /** @return string[]|null */
    public function getLanguageTags(): ?array { return $this->languageTags; }
    /** @param string[]|null $v */
    public function setLanguageTags(?array $v): self { $this->languageTags = $v; return $this; }

    /** @return string[]|null */
    public function getTags(): ?array { return $this->tags; }
    /** @param string[]|null $v */
    public function setTags(?array $v): self { $this->tags = $v; return $this; }

    public function getPostingPolicy(): ?string { return $this->postingPolicy; }
    public function setPostingPolicy(?string $v): self { $this->postingPolicy = $v; return $this; }

    public function getPaymentsUrl(): ?string { return $this->paymentsUrl; }
    public function setPaymentsUrl(?string $v): self { $this->paymentsUrl = $v; return $this; }

    public function getIcon(): ?string { return $this->icon; }
    public function setIcon(?string $v): self { $this->icon = $v; return $this; }

    /** @return array<string,mixed>|null */
    public function getFees(): ?array { return $this->fees; }
    /** @param array<string,mixed>|null $v */
    public function setFees(?array $v): self { $this->fees = $v; return $this; }

    public function isAuthRequired(): bool { return $this->authRequired; }
    public function setAuthRequired(bool $v): self { $this->authRequired = $v; return $this; }

    public function getFetchedAt(): ?\DateTimeImmutable { return $this->fetchedAt; }
    public function setFetchedAt(?\DateTimeImmutable $v): self { $this->fetchedAt = $v; return $this; }

    public function getFetchError(): ?string { return $this->fetchError; }
    public function setFetchError(?string $v): self { $this->fetchError = $v; return $this; }

    public function getFetchAttempts(): int { return $this->fetchAttempts; }
    public function setFetchAttempts(int $v): self { $this->fetchAttempts = max(0, $v); return $this; }
    public function incrementFetchAttempts(): self { $this->fetchAttempts++; return $this; }
}

