<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261116233158 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record shared local subscription revisions and link delivered feed items; preserve all payment history.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE subscription_change (event_id VARCHAR(64) NOT NULL, coordinate VARCHAR(512) NOT NULL, kind INT NOT NULL, pubkey VARCHAR(64) NOT NULL, created_at INT NOT NULL, recorded_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, tags JSONB NOT NULL, content_hash VARCHAR(64) NOT NULL, previous_event_id VARCHAR(64) DEFAULT NULL, changes JSONB NOT NULL, PRIMARY KEY (event_id))');
        $this->addSql('CREATE INDEX idx_subscription_change_source ON subscription_change (coordinate, created_at)');
        $this->addSql('ALTER TABLE notification ADD change_id VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE notification ADD CONSTRAINT FK_BF5476CA213C8BF4 FOREIGN KEY (change_id) REFERENCES subscription_change (event_id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_BF5476CA213C8BF4 ON notification (change_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE notification DROP CONSTRAINT FK_BF5476CA213C8BF4');
        $this->addSql('DROP INDEX IDX_BF5476CA213C8BF4');
        $this->addSql('ALTER TABLE notification DROP change_id');
        $this->addSql('DROP TABLE subscription_change');
    }
}
