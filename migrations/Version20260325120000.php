<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260325120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add subdomain column to visit table for subdomain analytics';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE visit ADD COLUMN subdomain VARCHAR(255) DEFAULT NULL
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_visit_subdomain ON visit (subdomain)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            DROP INDEX idx_visit_subdomain
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE visit DROP COLUMN subdomain
        SQL);
    }
}

