<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260314120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create follow_pack_source table for mapping follow pack purposes to event coordinates';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE follow_pack_source (
            id SERIAL PRIMARY KEY,
            purpose VARCHAR(50) NOT NULL UNIQUE,
            coordinate VARCHAR(500) NOT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
            updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT NOW()
        )');
        $this->addSql("COMMENT ON COLUMN follow_pack_source.created_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN follow_pack_source.updated_at IS '(DC2Type:datetime_immutable)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS follow_pack_source');
    }
}

