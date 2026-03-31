<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Create user_relay_list table and migrate synthetic relay list data from
 * the event table.
 *
 * Synthetic events (id LIKE 'relay_list_%', kind 10002, empty sig) were
 * server-generated cache entries that polluted the Nostr event table.
 * This migration moves them to a proper dedicated table.
 */
final class Version20260331120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create user_relay_list table, seed from synthetic events, delete synthetic events from event table';
    }

    public function up(Schema $schema): void
    {
        // 1. Create the new table
        $this->addSql('
            CREATE TABLE user_relay_list (
                id SERIAL PRIMARY KEY,
                pubkey VARCHAR(64) NOT NULL,
                read_relays JSONB NOT NULL DEFAULT \'[]\',
                write_relays JSONB NOT NULL DEFAULT \'[]\',
                created_at BIGINT NOT NULL DEFAULT 0,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT NOW()
            )
        ');

        $this->addSql('CREATE UNIQUE INDEX UNIQ_USER_RELAY_LIST_PUBKEY ON user_relay_list (pubkey)');
        $this->addSql('CREATE INDEX idx_user_relay_list_pubkey ON user_relay_list (pubkey)');

        $this->addSql("COMMENT ON TABLE user_relay_list IS 'Cached NIP-65 relay lists per user (replaces synthetic events in event table)'");

        // 2. Seed from existing synthetic events in the event table.
        //    Parse NIP-65 ["r", url, marker?] tags using PostgreSQL JSONB functions.
        //
        //    For each synthetic relay list event:
        //    - read_relays: tags where marker = 'read' OR marker is absent (both = read+write)
        //    - write_relays: tags where marker = 'write' OR marker is absent
        //
        //    We use DISTINCT ON (pubkey) to take only the latest per pubkey.
        $this->addSql("
            INSERT INTO user_relay_list (pubkey, read_relays, write_relays, created_at, updated_at)
            SELECT
                sub.pubkey,
                COALESCE(
                    (SELECT jsonb_agg(t.elem ->> 1)
                     FROM jsonb_array_elements(sub.tags) AS t(elem)
                     WHERE t.elem ->> 0 = 'r'
                       AND (t.elem ->> 2 IS NULL OR t.elem ->> 2 = '' OR t.elem ->> 2 = 'read')
                    ), '[]'::jsonb
                ) AS read_relays,
                COALESCE(
                    (SELECT jsonb_agg(t.elem ->> 1)
                     FROM jsonb_array_elements(sub.tags) AS t(elem)
                     WHERE t.elem ->> 0 = 'r'
                       AND (t.elem ->> 2 IS NULL OR t.elem ->> 2 = '' OR t.elem ->> 2 = 'write')
                    ), '[]'::jsonb
                ) AS write_relays,
                sub.created_at,
                NOW()
            FROM (
                SELECT DISTINCT ON (e.pubkey)
                    e.pubkey, e.tags, e.created_at
                FROM event e
                WHERE e.kind = 10002
                ORDER BY e.pubkey, e.created_at DESC
            ) sub
            ON CONFLICT (pubkey) DO NOTHING
        ");

        // 3. Remove synthetic events from the event table
        $this->addSql("DELETE FROM event WHERE id LIKE 'relay_list_%'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS user_relay_list');
    }
}

