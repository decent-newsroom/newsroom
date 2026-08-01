<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Restore a GIN index on event.tags for jsonb containment (@>) queries.
 *
 * A previous migration (Version20260323161221) dropped idx_event_tags_gin and
 * never recreated it, leaving containment/tag lookups to sequentially scan the
 * event table. The author-overview "featured reading lists" lookup filters
 * kind-30040 events by `tags @> '[["type","reading-list"]]'`, which this index
 * accelerates. jsonb_path_ops is smaller/faster than the default operator class
 * and fully supports the @> operator we use.
 */
final class Version20260801160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Restore GIN index on event.tags (jsonb_path_ops) for @> containment queries';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_event_tags_gin ON event USING GIN (tags jsonb_path_ops)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_event_tags_gin');
    }
}
