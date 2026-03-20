<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add self-sovereign admin support to ChatUser:
 * - Add main_app_user_id FK (nullable) linking to app_user for self-sovereign admins
 * - Make encrypted_private_key nullable (self-sovereign users don't have custodial keys)
 * - Add unique constraint on (community_id, main_app_user_id)
 */
final class Version20260320140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ChatUser: add main_app_user_id FK, make encrypted_private_key nullable for self-sovereign admin support';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE chat_user ADD main_app_user_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE chat_user ALTER encrypted_private_key DROP NOT NULL');
        $this->addSql('ALTER TABLE chat_user ADD CONSTRAINT FK_chat_user_main_app_user FOREIGN KEY (main_app_user_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_chat_user_main_app_user ON chat_user (main_app_user_id)');
        $this->addSql('CREATE UNIQUE INDEX chat_user_community_main_user ON chat_user (community_id, main_app_user_id) WHERE main_app_user_id IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS chat_user_community_main_user');
        $this->addSql('DROP INDEX IF EXISTS IDX_chat_user_main_app_user');
        $this->addSql('ALTER TABLE chat_user DROP CONSTRAINT IF EXISTS FK_chat_user_main_app_user');
        $this->addSql('ALTER TABLE chat_user DROP main_app_user_id');
        $this->addSql('ALTER TABLE chat_user ALTER encrypted_private_key SET NOT NULL');
    }
}


