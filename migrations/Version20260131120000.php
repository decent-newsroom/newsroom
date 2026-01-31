<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration to create rememberme_token table for persistent login sessions.
 */
final class Version20260131120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create rememberme_token table for persistent remember-me sessions';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE rememberme_token (
            series VARCHAR(88) PRIMARY KEY,
            value VARCHAR(88) NOT NULL,
            lastUsed DATETIME NOT NULL,
            class VARCHAR(100) NOT NULL,
            username VARCHAR(200) NOT NULL
        )');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE rememberme_token');
    }
}
