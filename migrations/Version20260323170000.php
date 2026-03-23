<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Re-create graph-layer tables (current_record, parsed_reference) that were
 * accidentally dropped by the auto-generated Version20260323161221 migration.
 *
 * These tables are raw-SQL (not Doctrine-managed entities) and must be
 * excluded from future diffs via doctrine.dbal.schema_filter.
 */
final class Version20260323170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Re-create graph-layer tables current_record and parsed_reference';
    }

    public function up(Schema $schema): void
    {
        // ── parsed_reference ────────────────────────────────────────────────
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS parsed_reference (
                id SERIAL PRIMARY KEY,
                source_event_id VARCHAR(225) NOT NULL,
                tag_name VARCHAR(10) NOT NULL DEFAULT 'a',
                target_ref_type VARCHAR(20) NOT NULL DEFAULT 'coordinate',
                target_kind INT,
                target_pubkey VARCHAR(255),
                target_d_tag VARCHAR(512),
                target_coord VARCHAR(800),
                relation VARCHAR(50) NOT NULL DEFAULT 'references',
                marker VARCHAR(255),
                position INT NOT NULL DEFAULT 0,
                is_structural BOOLEAN NOT NULL DEFAULT FALSE,
                is_resolvable BOOLEAN NOT NULL DEFAULT TRUE
            )
        SQL);

        $this->addSql('CREATE INDEX IF NOT EXISTS idx_parsed_ref_source ON parsed_reference (source_event_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_parsed_ref_target_coord ON parsed_reference (target_coord)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_parsed_ref_target_parts ON parsed_reference (target_kind, target_pubkey, target_d_tag)');

        // ── current_record ──────────────────────────────────────────────────
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS current_record (
                record_uid VARCHAR(800) PRIMARY KEY,
                coord VARCHAR(800) NOT NULL UNIQUE,
                kind INT NOT NULL,
                pubkey VARCHAR(255) NOT NULL,
                d_tag VARCHAR(512),
                current_event_id VARCHAR(225) NOT NULL,
                current_created_at BIGINT NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT NOW()
            )
        SQL);

        $this->addSql('CREATE INDEX IF NOT EXISTS idx_current_record_kind ON current_record (kind)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_current_record_pubkey ON current_record (pubkey)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS parsed_reference');
        $this->addSql('DROP TABLE IF EXISTS current_record');
    }
}

