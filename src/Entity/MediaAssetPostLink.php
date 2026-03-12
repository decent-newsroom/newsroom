<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Links cached media assets to published media posts.
 *
 * @see §12 of multimedia-manager spec
 */
#[ORM\Entity]
#[ORM\Table(name: 'media_asset_post_link')]
class MediaAssetPostLink
{
    #[ORM\Id]
    #[ORM\Column(length: 64)]
    private string $assetHash = '';

    #[ORM\Id]
    #[ORM\Column(length: 64)]
    private string $eventId = '';

    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private ?int $linkedAt = null;

    public function getAssetHash(): string { return $this->assetHash; }
    public function setAssetHash(string $v): self { $this->assetHash = $v; return $this; }
    public function getEventId(): string { return $this->eventId; }
    public function setEventId(string $v): self { $this->eventId = $v; return $this; }
    public function getLinkedAt(): ?int { return $this->linkedAt; }
    public function setLinkedAt(?int $v): self { $this->linkedAt = $v; return $this; }
}

