<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add `essayist_exclusive` flag to the `article` table.
 *
 * When true, the article must only be served to logged-in Essayist members
 * (or admins). Public listings, search results, and the single-article view
 * filter on this column so non-members never receive the article body.
 */
final class Version20260523120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add essayist_exclusive flag to article';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE article ADD COLUMN essayist_exclusive BOOLEAN NOT NULL DEFAULT FALSE');
        $this->addSql('CREATE INDEX idx_article_essayist_exclusive ON article (essayist_exclusive) WHERE essayist_exclusive = TRUE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_article_essayist_exclusive');
        $this->addSql('ALTER TABLE article DROP COLUMN essayist_exclusive');
    }
}

