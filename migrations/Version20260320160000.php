<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Chat push notifications: add ChatPushSubscription table and mutedNotifications to ChatGroupMembership.
 */
final class Version20260320160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add chat_push_subscription table and muted_notifications column to chat_group_membership';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE chat_push_subscription (
            id SERIAL PRIMARY KEY,
            chat_user_id INT NOT NULL,
            endpoint TEXT NOT NULL,
            public_key VARCHAR(255) NOT NULL,
            auth_token VARCHAR(255) NOT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            CONSTRAINT fk_chat_push_user FOREIGN KEY (chat_user_id) REFERENCES chat_user (id) ON DELETE CASCADE
        )');

        $this->addSql('CREATE UNIQUE INDEX chat_push_endpoint_unique ON chat_push_subscription (endpoint)');
        $this->addSql('CREATE INDEX idx_chat_push_user ON chat_push_subscription (chat_user_id)');

        $this->addSql('COMMENT ON COLUMN chat_push_subscription.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN chat_push_subscription.expires_at IS \'(DC2Type:datetime_immutable)\'');

        $this->addSql('ALTER TABLE chat_group_membership ADD muted_notifications BOOLEAN NOT NULL DEFAULT false');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS chat_push_subscription');
        $this->addSql('ALTER TABLE chat_group_membership DROP COLUMN IF EXISTS muted_notifications');
    }
}

