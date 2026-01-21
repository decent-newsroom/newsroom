<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Make articleCoordinate nullable in highlight table to support highlights without article references
 */
final class Version20260121200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Make articleCoordinate nullable in highlight table to support highlights without article references';
    }

    public function up(Schema $schema): void
    {
        // Make article_coordinate nullable
        $this->addSql('ALTER TABLE highlight ALTER COLUMN article_coordinate DROP NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // Note: This cannot be safely reversed if there are NULL values
        // We'll keep it nullable in the down migration to avoid data loss
        $this->addSql('-- Cannot safely make article_coordinate NOT NULL again if NULL values exist');
    }
}
