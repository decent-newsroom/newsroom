<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251105000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add advanced_metadata field to article table for storing Nostr advanced tags';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE article ADD advanced_metadata JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE article DROP advanced_metadata');
    }
}

