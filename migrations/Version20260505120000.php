<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * NIP-11 Relay Information Document cache.
 *
 * Creates `relay_information` — one row per canonical relay URL, storing
 * the parsed NIP-11 document plus fetch-state fields.
 */
final class Version20260505120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'NIP-11: relay_information table for cached Relay Information Documents';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE relay_information (
                url               VARCHAR(255)   NOT NULL,
                name              VARCHAR(255)   DEFAULT NULL,
                description       TEXT           DEFAULT NULL,
                pubkey            CHAR(64)       DEFAULT NULL,
                contact           VARCHAR(255)   DEFAULT NULL,
                software          VARCHAR(255)   DEFAULT NULL,
                version           VARCHAR(64)    DEFAULT NULL,
                supported_nips    JSON           NOT NULL DEFAULT '[]',
                limitation        JSON           DEFAULT NULL,
                relay_countries   JSON           DEFAULT NULL,
                language_tags     JSON           DEFAULT NULL,
                tags              JSON           DEFAULT NULL,
                posting_policy    VARCHAR(512)   DEFAULT NULL,
                payments_url      VARCHAR(512)   DEFAULT NULL,
                icon              VARCHAR(512)   DEFAULT NULL,
                fees              JSON           DEFAULT NULL,
                auth_required     BOOLEAN        NOT NULL DEFAULT FALSE,
                fetched_at        TIMESTAMPTZ    DEFAULT NULL,
                fetch_error       TEXT           DEFAULT NULL,
                fetch_attempts    INTEGER        NOT NULL DEFAULT 0,
                PRIMARY KEY (url)
            )
        SQL);

        $this->addSql('CREATE INDEX idx_relay_information_fetched_at     ON relay_information (fetched_at)');
        $this->addSql('CREATE INDEX idx_relay_information_auth_required  ON relay_information (auth_required)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS relay_information');
    }
}

