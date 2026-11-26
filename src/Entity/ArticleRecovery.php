<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'article_recovery')]
#[ORM\Index(columns: ['pubkey', 'updated_at'], name: 'idx_article_recovery_owner_updated')]
class ArticleRecovery
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $id;

    #[ORM\Column(length: 64)]
    private string $pubkey;

    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $snapshot;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $id, string $pubkey, array $snapshot)
    {
        $this->id = $id;
        $this->pubkey = $pubkey;
        $this->update($snapshot);
    }

    public function update(array $snapshot): void
    {
        $this->snapshot = $snapshot;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): string { return $this->id; }
    public function getSnapshot(): array { return $this->snapshot; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
}
