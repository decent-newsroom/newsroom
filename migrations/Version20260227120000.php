<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add GIN index on event.tags JSONB column.
 *
 * The ReadingListNavigationService uses `tags::jsonb @> ?::jsonb` containment
 * queries on every article page load. Without a GIN index, this causes a full
 * table scan of the event table, contributing to slow article responses and
 * intermittent 502 errors from proxy timeouts.
 */
final class Version20260227120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add GIN index on event.tags for JSONB containment queries';
    }

    public function up(Schema $schema): void
    {
        // The tags column is type json (not jsonb), so we create an expression index
        // that casts to jsonb. This supports the @> containment operator used by
        // ReadingListNavigationService and other raw SQL queries.
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_event_tags_gin ON event USING GIN ((tags::jsonb) jsonb_path_ops)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_event_tags_gin');
    }
}

