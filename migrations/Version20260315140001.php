<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * AGE Phase 1 — Create parsed_reference and current_record tables.
 *
 * parsed_reference stores normalized outgoing references parsed from event tags.
 * current_record tracks the current (newest) event version for each coordinate.
 */
final class Version20260315140001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create parsed_reference and current_record tables for graph layer groundwork';
    }

    public function up(Schema $schema): void
    {
        // ── parsed_reference ────────────────────────────────────────────────
        $this->addSql(<<<'SQL'
            CREATE TABLE parsed_reference (
                id SERIAL PRIMARY KEY,
                source_event_id VARCHAR(225) NOT NULL,
                tag_name VARCHAR(10) NOT NULL DEFAULT 'a',
                target_ref_type VARCHAR(20) NOT NULL DEFAULT 'coordinate',
                target_kind INT,
                target_pubkey VARCHAR(255),
                target_d_tag VARCHAR(512),
                target_coord VARCHAR(800),
                relation VARCHAR(50) NOT NULL DEFAULT 'references',
                marker VARCHAR(50),
                position INT NOT NULL DEFAULT 0,
                is_structural BOOLEAN NOT NULL DEFAULT FALSE,
                is_resolvable BOOLEAN NOT NULL DEFAULT TRUE
            )
        SQL);

        $this->addSql('CREATE INDEX idx_parsed_ref_source ON parsed_reference (source_event_id)');
        $this->addSql('CREATE INDEX idx_parsed_ref_target_coord ON parsed_reference (target_coord)');
        $this->addSql('CREATE INDEX idx_parsed_ref_target_parts ON parsed_reference (target_kind, target_pubkey, target_d_tag)');

        // ── current_record ──────────────────────────────────────────────────
        $this->addSql(<<<'SQL'
            CREATE TABLE current_record (
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

        $this->addSql('CREATE INDEX idx_current_record_kind ON current_record (kind)');
        $this->addSql('CREATE INDEX idx_current_record_pubkey ON current_record (pubkey)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS parsed_reference');
        $this->addSql('DROP TABLE IF EXISTS current_record');
    }
}

