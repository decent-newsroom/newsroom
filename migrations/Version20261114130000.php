<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261114130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store bounded profile reference auxiliary tag slots';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE parsed_reference ADD auxiliary_data TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE parsed_reference DROP auxiliary_data');
    }
}
