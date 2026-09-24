<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260924130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store publication footer links in Unfold settings';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE unfold_publication_settings ADD footer_links JSON NOT NULL DEFAULT '[]'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE unfold_publication_settings DROP footer_links');
    }
}
