<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260330120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add follow_pack_coordinate column to app_user for storing the user selected recommendation follow pack';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user ADD follow_pack_coordinate VARCHAR(500) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user DROP COLUMN follow_pack_coordinate');
    }
}

