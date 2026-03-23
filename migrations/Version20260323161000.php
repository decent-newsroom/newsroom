<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add compound index on highlight(article_coordinate, cached_at) to optimize
 * cache-status lookups that previously required two separate queries.
 */
final class Version20260323161000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add compound index idx_article_coordinate_cached_at on highlight table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_article_coordinate_cached_at ON highlight (article_coordinate, cached_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_article_coordinate_cached_at');
    }
}

