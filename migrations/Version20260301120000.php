<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Fix event.tags column type and add GIN index.
 *
 * The previous migration (Version20260227120000) attempted to create a GIN index
 * with jsonb_path_ops on the tags column, but the column is type `json` (not `jsonb`).
 * PostgreSQL rejects jsonb_path_ops on a json column even with a cast expression,
 * producing: SQLSTATE[42804] operator class "jsonb_path_ops" does not accept data type json
 *
 * This meant the index was never created, so every `@>` containment query on event.tags
 * performed a full table scan — causing slow article page loads and intermittent 502
 * errors from proxy timeouts.
 *
 * This migration:
 *  1. Drops the (possibly partially created) index from the earlier attempt
 *  2. Converts event.tags from json → jsonb
 *  3. Creates the GIN index with jsonb_path_ops on the native jsonb column
 */
final class Version20260301120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Convert event.tags from json to jsonb and add GIN index for containment queries';
    }

    public function up(Schema $schema): void
    {
        // Safety: drop the index if the earlier migration left a partial/broken one
        $this->addSql('DROP INDEX IF EXISTS idx_event_tags_gin');

        // Convert the column from json to jsonb so jsonb_path_ops is accepted
        $this->addSql('ALTER TABLE event ALTER COLUMN tags TYPE jsonb USING tags::jsonb');

        // Create the GIN index — jsonb_path_ops is optimal for @> containment queries
        $this->addSql('CREATE INDEX idx_event_tags_gin ON event USING GIN (tags jsonb_path_ops)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_event_tags_gin');
        $this->addSql('ALTER TABLE event ALTER COLUMN tags TYPE json USING tags::json');
    }
}

