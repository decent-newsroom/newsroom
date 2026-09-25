<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260924140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store selected Unfold About article and relay hints';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE unfold_publication_settings ADD about_article_coordinate VARCHAR(500) DEFAULT NULL');
        $this->addSql("ALTER TABLE unfold_publication_settings ADD about_relay_hints JSON NOT NULL DEFAULT '[]'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE unfold_publication_settings DROP about_article_coordinate');
        $this->addSql('ALTER TABLE unfold_publication_settings DROP about_relay_hints');
    }
}
