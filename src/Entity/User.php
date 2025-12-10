<?php

namespace App\Entity;

use App\Enum\RolesEnum;
use App\Repository\UserEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\EquatableInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Entity storing local user representations
 */
#[ORM\Entity(repositoryClass: UserEntityRepository::class)]
#[ORM\Table(name: "app_user")]
class User implements UserInterface, EquatableInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(unique: true)]
    private ?string $npub = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private array $roles = [];

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $displayName = null;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $name = null;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $nip05 = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $about = null;

    #[ORM\Column(type: Types::STRING, length: 500, nullable: true)]
    private ?string $website = null;

    #[ORM\Column(type: Types::STRING, length: 500, nullable: true)]
    private ?string $picture = null;

    #[ORM\Column(type: Types::STRING, length: 500, nullable: true)]
    private ?string $banner = null;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $lud16 = null;

    private $metadata = null;
    private $relays = null;

    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';

        return $roles;
    }

    public function setRoles(?array $roles): self
    {
        $this->roles = $roles;

        return $this;
    }

    public function addRole(string $role): self
    {
        if (!in_array($role, $this->roles)) {
            $this->roles[] = $role;
        }

        return $this;
    }

    public function removeRole(string $role): self
    {
        $this->roles = array_filter($this->roles, fn($r) => $r !== $role);
        return $this;
    }

    public function isFeaturedWriter(): bool
    {
        return in_array(RolesEnum::FEATURED_WRITER, $this->roles, true);
    }

    public function isMuted(): bool
    {
        return in_array(RolesEnum::MUTED, $this->roles, true);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(?int $id): void
    {
        $this->id = $id;
    }

    public function getNpub(): ?string
    {
        return $this->npub;
    }

    public function setNpub(?string $npub): void
    {
        $this->npub = $npub;
    }

    public function eraseCredentials(): void
    {
        $this->metadata = null;
        $this->relays = null;
    }

    public function getUserIdentifier(): string
    {
        return $this->getNpub();
    }

    public function setMetadata(?object $metadata): void
    {
        $this->metadata = $metadata;
    }

    public function getMetadata(): ?object
    {
        return $this->metadata;
    }

    public function setRelays(?array $relays): void
    {
        $this->relays = $relays;
    }

    public function getRelays(): ?array
    {
        return $this->relays;
    }

    public function getName(): ?string
    {
        // Return stored name first, fallback to metadata, then npub
        return $this->name ?? $this->getMetadata()->name ?? $this->getUserIdentifier();
    }

    public function getDisplayName(): ?string
    {
        return $this->displayName;
    }

    public function setDisplayName(?string $displayName): self
    {
        $this->displayName = $displayName;
        return $this;
    }

    public function setName(?string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function getNip05(): ?string
    {
        return $this->nip05;
    }

    public function setNip05(?string $nip05): self
    {
        $this->nip05 = $nip05;
        return $this;
    }

    public function getAbout(): ?string
    {
        return $this->about;
    }

    public function setAbout(?string $about): self
    {
        $this->about = $about;
        return $this;
    }

    public function getWebsite(): ?string
    {
        return $this->website;
    }

    public function setWebsite(?string $website): self
    {
        $this->website = $website;
        return $this;
    }

    public function getPicture(): ?string
    {
        return $this->picture;
    }

    public function setPicture(?string $picture): self
    {
        $this->picture = $picture;
        return $this;
    }

    public function getBanner(): ?string
    {
        return $this->banner;
    }

    public function setBanner(?string $banner): self
    {
        $this->banner = $banner;
        return $this;
    }

    public function getLud16(): ?string
    {
        return $this->lud16;
    }

    public function setLud16(?string $lud16): self
    {
        $this->lud16 = $lud16;
        return $this;
    }

    public function isEqualTo(UserInterface $user): bool
    {
        return $this->getUserIdentifier() === $user->getUserIdentifier();
    }

    public function __serialize(): array
    {
        return [
            'id' => $this->id,
            'npub' => $this->npub,
            'roles' => $this->roles,
            'metadata' => $this->metadata,
            'relays' => $this->relays
        ];
    }

    public function __unserialize(array $data): void
    {
        $this->id = $data['id'];
        $this->npub = $data['npub'];
        $this->roles = $data['roles'];
        $this->metadata = $data['metadata'];
        $this->relays = $data['relays'];
    }
}
