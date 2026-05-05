<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * NIP-66 Relay Discovery / Liveness monitoring tables.
 *
 * Creates:
 *   relay_monitor          — kind 10166 self-announcements (one per monitor pubkey)
 *   monitored_relay        — kind 30166 observations (one per monitor × relay URL)
 *   trusted_relay_monitor  — operator-approved monitor pubkeys
 */
final class Version20260505130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'NIP-66: relay_monitor, monitored_relay, trusted_relay_monitor tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE relay_monitor (
                pubkey              CHAR(64)        NOT NULL,
                name                VARCHAR(255)    DEFAULT NULL,
                frequency_seconds   INTEGER         DEFAULT NULL,
                monitored_relays    JSON            NOT NULL DEFAULT '[]',
                checks              JSON            DEFAULT NULL,
                event_id            CHAR(64)        NOT NULL,
                event_created_at    BIGINT          NOT NULL,
                updated_at          TIMESTAMPTZ     NOT NULL,
                PRIMARY KEY (pubkey)
            )
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE monitored_relay (
                id               BIGSERIAL        NOT NULL,
                monitor_pubkey   CHAR(64)         NOT NULL,
                relay_url        VARCHAR(255)     NOT NULL,
                rtt_open_ms      INTEGER          DEFAULT NULL,
                rtt_read_ms      INTEGER          DEFAULT NULL,
                rtt_write_ms     INTEGER          DEFAULT NULL,
                accepted_kinds   JSON             NOT NULL DEFAULT '[]',
                supported_nips   JSON             NOT NULL DEFAULT '[]',
                requirements     JSON             NOT NULL DEFAULT '[]',
                topics           JSON             DEFAULT NULL,
                geo              JSON             DEFAULT NULL,
                event_id         CHAR(64)         NOT NULL,
                event_created_at BIGINT           NOT NULL,
                observed_at      TIMESTAMPTZ      NOT NULL,
                PRIMARY KEY (id)
            )
        SQL);

        $this->addSql('CREATE UNIQUE INDEX uniq_monitored_relay         ON monitored_relay (monitor_pubkey, relay_url)');
        $this->addSql('CREATE INDEX idx_monitored_relay_url             ON monitored_relay (relay_url)');
        $this->addSql('CREATE INDEX idx_monitored_relay_observed_at     ON monitored_relay (observed_at DESC)');

        $this->addSql(<<<'SQL'
            CREATE TABLE trusted_relay_monitor (
                pubkey              CHAR(64)        NOT NULL,
                trusted_at          TIMESTAMPTZ     NOT NULL DEFAULT NOW(),
                trusted_by_user_id  INTEGER         DEFAULT NULL,
                note                VARCHAR(512)    DEFAULT NULL,
                PRIMARY KEY (pubkey)
            )
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS trusted_relay_monitor');
        $this->addSql('DROP TABLE IF EXISTS monitored_relay');
        $this->addSql('DROP TABLE IF EXISTS relay_monitor');
    }
}

