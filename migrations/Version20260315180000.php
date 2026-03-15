<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Widen parsed_reference.marker from VARCHAR(50) to VARCHAR(255).
 * Some a-tag markers in the wild are longer than 50 characters.
 */
final class Version20260315180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Widen parsed_reference.marker column to VARCHAR(255)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE parsed_reference ALTER COLUMN marker TYPE VARCHAR(255)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE parsed_reference ALTER COLUMN marker TYPE VARCHAR(50)');
    }
}

