<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add composite index on article(pubkey, created_at DESC) to optimize follows feed queries.
 *
 * The follows tab queries: WHERE pubkey IN (...) ORDER BY created_at DESC LIMIT 50
 * Without this index, PostgreSQL must seq-scan or sort the entire table.
 */
final class Version20260320180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add composite index on article(pubkey, created_at DESC) for follows feed performance';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_article_pubkey_created ON article (pubkey, created_at DESC)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_article_pubkey_created');
    }
}

