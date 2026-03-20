<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ChatBundle: Create 7 tables for community chat (relay-only messages, no message table).
 */
final class Version20260320120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create ChatBundle tables: chat_community, chat_user, chat_community_membership, chat_group, chat_group_membership, chat_invite, chat_session';
    }

    public function up(Schema $schema): void
    {
        // chat_community
        $this->addSql('CREATE TABLE chat_community (
            id SERIAL PRIMARY KEY,
            subdomain VARCHAR(255) NOT NULL,
            name VARCHAR(255) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT \'active\',
            relay_url VARCHAR(500) DEFAULT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL
        )');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_chat_community_subdomain ON chat_community (subdomain)');
        $this->addSql("COMMENT ON COLUMN chat_community.created_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN chat_community.updated_at IS '(DC2Type:datetime_immutable)'");

        // chat_user
        $this->addSql('CREATE TABLE chat_user (
            id SERIAL PRIMARY KEY,
            community_id INT NOT NULL REFERENCES chat_community(id),
            display_name VARCHAR(255) NOT NULL,
            about TEXT DEFAULT NULL,
            pubkey VARCHAR(64) NOT NULL,
            encrypted_private_key TEXT NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT \'pending\',
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            activated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL
        )');
        $this->addSql('CREATE UNIQUE INDEX chat_user_community_pubkey ON chat_user (community_id, pubkey)');
        $this->addSql('CREATE INDEX IDX_chat_user_pubkey ON chat_user (pubkey)');
        $this->addSql("COMMENT ON COLUMN chat_user.created_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN chat_user.activated_at IS '(DC2Type:datetime_immutable)'");

        // chat_community_membership
        $this->addSql('CREATE TABLE chat_community_membership (
            id SERIAL PRIMARY KEY,
            user_id INT NOT NULL REFERENCES chat_user(id),
            community_id INT NOT NULL REFERENCES chat_community(id),
            role VARCHAR(20) NOT NULL DEFAULT \'user\',
            joined_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL
        )');
        $this->addSql('CREATE UNIQUE INDEX chat_cm_user_community ON chat_community_membership (user_id, community_id)');
        $this->addSql("COMMENT ON COLUMN chat_community_membership.joined_at IS '(DC2Type:datetime_immutable)'");

        // chat_group
        $this->addSql('CREATE TABLE chat_group (
            id SERIAL PRIMARY KEY,
            community_id INT NOT NULL REFERENCES chat_community(id),
            slug VARCHAR(255) NOT NULL,
            name VARCHAR(255) NOT NULL,
            channel_event_id VARCHAR(64) DEFAULT NULL,
            status VARCHAR(20) NOT NULL DEFAULT \'active\',
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL
        )');
        $this->addSql('CREATE UNIQUE INDEX chat_group_community_slug ON chat_group (community_id, slug)');
        $this->addSql("COMMENT ON COLUMN chat_group.created_at IS '(DC2Type:datetime_immutable)'");

        // chat_group_membership
        $this->addSql('CREATE TABLE chat_group_membership (
            id SERIAL PRIMARY KEY,
            user_id INT NOT NULL REFERENCES chat_user(id),
            group_id INT NOT NULL REFERENCES chat_group(id),
            role VARCHAR(20) NOT NULL DEFAULT \'member\',
            joined_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL
        )');
        $this->addSql('CREATE UNIQUE INDEX chat_gm_user_group ON chat_group_membership (user_id, group_id)');
        $this->addSql("COMMENT ON COLUMN chat_group_membership.joined_at IS '(DC2Type:datetime_immutable)'");

        // chat_invite
        $this->addSql('CREATE TABLE chat_invite (
            id SERIAL PRIMARY KEY,
            community_id INT NOT NULL REFERENCES chat_community(id),
            group_id INT DEFAULT NULL REFERENCES chat_group(id),
            token_hash VARCHAR(64) NOT NULL,
            type VARCHAR(20) NOT NULL DEFAULT \'activation\',
            role_to_grant VARCHAR(20) NOT NULL DEFAULT \'user\',
            created_by_id INT NOT NULL REFERENCES chat_user(id),
            max_uses INT DEFAULT NULL,
            used_count INT NOT NULL DEFAULT 0,
            expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            revoked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL
        )');
        $this->addSql('CREATE INDEX chat_invite_token_hash ON chat_invite (token_hash)');
        $this->addSql("COMMENT ON COLUMN chat_invite.expires_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN chat_invite.revoked_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN chat_invite.created_at IS '(DC2Type:datetime_immutable)'");

        // chat_session
        $this->addSql('CREATE TABLE chat_session (
            id SERIAL PRIMARY KEY,
            user_id INT NOT NULL REFERENCES chat_user(id),
            community_id INT NOT NULL REFERENCES chat_community(id),
            session_token VARCHAR(128) NOT NULL,
            expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            revoked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            last_seen_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL
        )');
        $this->addSql('CREATE INDEX chat_session_token ON chat_session (session_token)');
        $this->addSql("COMMENT ON COLUMN chat_session.expires_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN chat_session.revoked_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN chat_session.created_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN chat_session.last_seen_at IS '(DC2Type:datetime_immutable)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS chat_session');
        $this->addSql('DROP TABLE IF EXISTS chat_invite');
        $this->addSql('DROP TABLE IF EXISTS chat_group_membership');
        $this->addSql('DROP TABLE IF EXISTS chat_group');
        $this->addSql('DROP TABLE IF EXISTS chat_community_membership');
        $this->addSql('DROP TABLE IF EXISTS chat_user');
        $this->addSql('DROP TABLE IF EXISTS chat_community');
    }
}

