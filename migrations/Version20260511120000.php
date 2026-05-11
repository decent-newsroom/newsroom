<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260511120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add user_agent and is_bot columns to the visit table for bot-traffic differentiation';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE visit ADD user_agent VARCHAR(512) DEFAULT NULL");
        $this->addSql("ALTER TABLE visit ADD is_bot BOOLEAN NOT NULL DEFAULT FALSE");
        $this->addSql("CREATE INDEX idx_visit_is_bot ON visit (is_bot)");
        $this->addSql("CREATE INDEX idx_visit_is_bot_visited_at ON visit (is_bot, visited_at)");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DROP INDEX idx_visit_is_bot_visited_at");
        $this->addSql("DROP INDEX idx_visit_is_bot");
        $this->addSql("ALTER TABLE visit DROP COLUMN is_bot");
        $this->addSql("ALTER TABLE visit DROP COLUMN user_agent");
    }
}

