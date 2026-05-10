<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Create article_in_publication index table.
 *
 * Tracks which kind 30040 publication events (magazines / reading-list
 * categories) directly include a specific article coordinate.
 * Maintained by ArticlePublicationIndexer; rebuilt via
 * `bin/console app:rebuild-publication-index`.
 */
final class Version20260510120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create article_in_publication index table for reverse magazine lookup';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("
            CREATE TABLE article_in_publication (
                id              SERIAL         PRIMARY KEY,
                article_coordinate VARCHAR(512) NOT NULL,
                container_pubkey   VARCHAR(64)  NOT NULL,
                container_d_tag    VARCHAR(512) NOT NULL,
                container_title    VARCHAR(512) NULL,
                updated_at         TIMESTAMP    NOT NULL DEFAULT NOW(),
                CONSTRAINT uq_aip_article_container
                    UNIQUE (article_coordinate, container_pubkey, container_d_tag)
            )
        ");

        $this->addSql('CREATE INDEX idx_aip_article_coord ON article_in_publication (article_coordinate)');
        $this->addSql('CREATE INDEX idx_aip_container ON article_in_publication (container_pubkey, container_d_tag)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS article_in_publication');
    }
}

