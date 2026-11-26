<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261126120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store private account-scoped article recovery copies.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE article_recovery (id VARCHAR(36) NOT NULL, pubkey VARCHAR(64) NOT NULL, snapshot JSONB NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_article_recovery_owner_updated ON article_recovery (pubkey, updated_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE article_recovery');
    }
}
