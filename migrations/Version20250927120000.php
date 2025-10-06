<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add indexes to optimize magazine queries
 */
final class Version20250927120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add database indexes to optimize magazine queries';
    }

    public function up(Schema $schema): void
    {
        // Add index on event.kind for efficient magazine event queries
        $this->addSql('CREATE INDEX IF NOT EXISTS IDX_EVENT_KIND ON event (kind)');

        // Add composite index on event.kind and created_at for sorted magazine queries
        $this->addSql('CREATE INDEX IF NOT EXISTS IDX_EVENT_KIND_CREATED ON event (kind, created_at DESC)');

        // Add index on article.slug for efficient article lookups
        $this->addSql('CREATE INDEX IF NOT EXISTS IDX_ARTICLE_SLUG ON article (slug)');

        // Add index on event.pubkey for author-based queries
        $this->addSql('CREATE INDEX IF NOT EXISTS IDX_EVENT_PUBKEY ON event (pubkey)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS IDX_EVENT_KIND');
        $this->addSql('DROP INDEX IF EXISTS IDX_EVENT_KIND_CREATED');
        $this->addSql('DROP INDEX IF EXISTS IDX_ARTICLE_SLUG');
        $this->addSql('DROP INDEX IF EXISTS IDX_EVENT_PUBKEY');
    }
}
