<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * AGE Phase 0 — Add d_tag column to event table.
 *
 * The d tag is the identity axis for every parameterized replaceable event
 * (kinds 30000–39999). Storing it as a dedicated column enables fast
 * coordinate lookups via the composite index (kind, pubkey, d_tag).
 */
final class Version20260315140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add d_tag column to event table with composite coordinate index and backfill from JSONB tags';
    }

    public function up(Schema $schema): void
    {
        // 1. Add the column
        $this->addSql('ALTER TABLE event ADD COLUMN d_tag VARCHAR(512) DEFAULT NULL');

        // 2. Backfill d_tag for all parameterized replaceable events (kinds 30000–39999).
        //    Extract the first ["d", "..."] tag value from the JSONB tags array.
        //    If the d tag is absent or has an empty value, store '' (empty string).
        //    NULL is reserved for events outside the 30000–39999 range.
        $this->addSql(<<<'SQL'
            UPDATE event
            SET d_tag = COALESCE(
                (
                    SELECT tag->>1
                    FROM jsonb_array_elements(tags) AS tag
                    WHERE tag->>0 = 'd'
                    LIMIT 1
                ),
                ''
            )
            WHERE kind >= 30000 AND kind <= 39999
        SQL);

        // 3. Create the composite coordinate index (partial — only where d_tag is populated)
        $this->addSql('CREATE INDEX idx_event_coord ON event (kind, pubkey, d_tag) WHERE d_tag IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_event_coord');
        $this->addSql('ALTER TABLE event DROP COLUMN IF EXISTS d_tag');
    }
}

