<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Convert event.tags from json to jsonb and add a GIN index.
 *
 * The column must be jsonb because 13+ queries use the @> containment operator
 * and 4+ queries use jsonb_array_elements(), neither of which work on plain json.
 *
 * The GIN index with jsonb_path_ops makes @> containment lookups use an index
 * scan instead of a sequential scan of the entire event table.
 */
final class Version20260303120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Convert event.tags to jsonb and add GIN index for containment queries';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE event ALTER COLUMN tags TYPE jsonb USING tags::jsonb');
        $this->addSql('CREATE INDEX idx_event_tags_gin ON event USING GIN (tags jsonb_path_ops)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_event_tags_gin');
        $this->addSql('ALTER TABLE event ALTER COLUMN tags TYPE json USING tags::json');
    }
}

