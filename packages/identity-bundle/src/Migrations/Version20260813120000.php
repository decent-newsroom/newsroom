<?php

declare(strict_types=1);

namespace IdentityBundleMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Creates the `identity_user_link` table — links external identities
 * (Nostr pubkey, email, WebAuthn credential id, OAuth subject, ...) to a host
 * application user, keyed by an opaque `owner_id` string rather than a
 * Doctrine foreign key (see DecentNewsroom\IdentityBundle\Entity\UserIdentityLink).
 */
final class Version20260813120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create identity_user_link table for DecentNewsroom\\IdentityBundle';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE identity_user_link (
                id SERIAL NOT NULL,
                owner_id VARCHAR(64) NOT NULL,
                provider VARCHAR(64) NOT NULL,
                external_id VARCHAR(255) NOT NULL,
                label VARCHAR(255) DEFAULT NULL,
                metadata JSON DEFAULT NULL,
                verified_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                last_used_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                PRIMARY KEY(id)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_provider_external_id ON identity_user_link (provider, external_id)');
        $this->addSql('CREATE INDEX idx_identity_link_owner_id ON identity_user_link (owner_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE identity_user_link');
    }
}
