# Skill: Add a Doctrine Entity with Migration

Use this skill to add a new PostgreSQL table backed by a Doctrine entity.

---

## 1. Create the Entity

File: `src/Entity/YourEntity.php`

```php
<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\YourEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: YourEntityRepository::class)]
#[ORM\Index(columns: ['pubkey', 'created_at'], name: 'idx_your_entity_pubkey_created')]
class YourEntity
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    /** Hex pubkey of the Nostr user this record belongs to */
    #[ORM\Column(length: 64)]
    private string $pubkey;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $reason = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $pubkey)
    {
        $this->pubkey    = $pubkey;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int                 { return $this->id; }
    public function getPubkey(): string           { return $this->pubkey; }
    public function getReason(): ?string          { return $this->reason; }
    public function setReason(?string $r): void   { $this->reason = $r; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
```

**Rules:**
- Use `Types::TEXT` for any string that may exceed 255 characters.
- Prefer `\DateTimeImmutable` over `\DateTime`.
- Use `nullable: true` only when the value is genuinely optional.
- Never store Nostr pubkeys longer than 64 hex characters — declare `length: 64`.
- Add a composite index when queries will filter by `pubkey + created_at`.

---

## 2. Create the Repository

File: `src/Repository/YourEntityRepository.php`

```php
<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\YourEntity;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<YourEntity>
 */
class YourEntityRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, YourEntity::class);
    }

    public function findLatestByPubkey(string $pubkey): ?YourEntity
    {
        return $this->createQueryBuilder('y')
            ->andWhere('y.pubkey = :pubkey')
            ->setParameter('pubkey', $pubkey)
            ->orderBy('y.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * IMPORTANT: When filtering JSON/JSONB columns on PostgreSQL, use JSONB
     * containment operators, NOT LIKE. Example:
     *   ->andWhere("y.tags::jsonb @> :tag")
     *   ->setParameter('tag', json_encode([['p', $pubkey]]))
     * Never use LIKE on JSON columns — it causes "operator does not exist: json ~~ unknown".
     */
}
```

---

## 3. Generate and run the migration

All commands run **inside the Docker container**:

```bash
# Diff the schema and generate a new migration file
docker compose exec php bin/console doctrine:migrations:diff

# Review the generated file in migrations/VersionYYYYMMDDHHMMSS.php
# then apply it:
docker compose exec php bin/console doctrine:migrations:migrate
```

Check the generated SQL carefully, especially for:
- Correct index names (Doctrine generates long names — shorten if over 63 chars for PostgreSQL).
- Any `ALTER TABLE` that may lock a large table in production.

---

## 4. Register in `config/doctrine.yaml` (if using custom type)

Only needed when adding a new PHP enum as a Doctrine type. Add a `dbal.types` entry:

```yaml
doctrine:
    dbal:
        types:
            your_enum_type: App\Doctrine\Type\YourEnumType
```

---

## 5. Add to `gitignore` exclusion in entity `.gitignore`

`src/Entity/.gitignore` already exists to exclude generated proxies; no action needed.

---

## Checklist

- [ ] Entity created in `src/Entity/` with proper ORM attributes
- [ ] Repository created in `src/Repository/` extending `ServiceEntityRepository`
- [ ] No `LIKE` on JSON/JSONB columns — use `@>` containment or PHP-side filtering
- [ ] Migration generated: `doctrine:migrations:diff`
- [ ] Migration reviewed and applied: `doctrine:migrations:migrate`
- [ ] Unit test (at minimum) for repository custom methods
- [ ] `CHANGELOG.md` entry added

