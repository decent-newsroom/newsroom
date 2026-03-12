<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Create media manager cache tables: media_asset_cache, media_post_cache, media_asset_post_link.
 *
 * These local cache tables allow the media manager to operate without
 * re-querying providers and relays on every page load.
 */
final class Version20260312120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create multimedia manager cache tables';
    }

    public function up(Schema $schema): void
    {
        // media_asset_cache
        $this->addSql('
            CREATE TABLE media_asset_cache (
                id SERIAL PRIMARY KEY,
                provider_id VARCHAR(64) NOT NULL,
                owner_pubkey VARCHAR(64) NOT NULL,
                asset_hash VARCHAR(64) NOT NULL,
                original_hash VARCHAR(64) DEFAULT NULL,
                url VARCHAR(512) NOT NULL,
                mime VARCHAR(64) DEFAULT NULL,
                size INTEGER DEFAULT NULL,
                dim VARCHAR(32) DEFAULT NULL,
                alt TEXT DEFAULT NULL,
                metadata_json JSONB DEFAULT NULL,
                uploaded_at BIGINT DEFAULT NULL,
                last_seen_at BIGINT DEFAULT NULL,
                CONSTRAINT uniq_asset_provider_hash UNIQUE (provider_id, asset_hash)
            )
        ');
        $this->addSql('CREATE INDEX idx_asset_owner ON media_asset_cache (owner_pubkey)');

        // media_post_cache
        $this->addSql('
            CREATE TABLE media_post_cache (
                event_id VARCHAR(64) PRIMARY KEY,
                pubkey VARCHAR(64) NOT NULL,
                kind SMALLINT NOT NULL,
                title VARCHAR(255) DEFAULT NULL,
                content TEXT DEFAULT NULL,
                primary_url VARCHAR(512) DEFAULT NULL,
                primary_hash VARCHAR(64) DEFAULT NULL,
                preview_url VARCHAR(512) DEFAULT NULL,
                duration DOUBLE PRECISION DEFAULT NULL,
                tags_json JSONB DEFAULT NULL,
                created_at BIGINT DEFAULT NULL,
                last_seen_at BIGINT DEFAULT NULL
            )
        ');
        $this->addSql('CREATE INDEX idx_post_pubkey ON media_post_cache (pubkey)');
        $this->addSql('CREATE INDEX idx_post_kind ON media_post_cache (kind)');

        // media_asset_post_link
        $this->addSql('
            CREATE TABLE media_asset_post_link (
                asset_hash VARCHAR(64) NOT NULL,
                event_id VARCHAR(64) NOT NULL,
                linked_at BIGINT DEFAULT NULL,
                PRIMARY KEY (asset_hash, event_id)
            )
        ');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS media_asset_post_link');
        $this->addSql('DROP TABLE IF EXISTS media_post_cache');
        $this->addSql('DROP TABLE IF EXISTS media_asset_cache');
    }
}

