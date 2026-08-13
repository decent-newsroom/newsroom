<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Supports DecentNewsroom\IdentityBundle: `npub` is no longer the only
 * possible Security identifier for an app_user row — accounts created via a
 * non-Nostr identity provider (email OTP, passkey, OAuth) get a generated
 * `local_identifier` (ULID) instead. See App\Entity\User::getUserIdentifier().
 *
 * Existing npub-only rows are completely unaffected: npub stays populated and
 * remains the preferred identifier whenever present.
 */
final class Version20260813130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Make app_user.npub nullable and add app_user.local_identifier for non-Nostr identities (IdentityBundle)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user ADD local_identifier VARCHAR(36) DEFAULT NULL');
        $this->addSql('ALTER TABLE app_user ALTER npub DROP NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_app_user_local_identifier ON app_user (local_identifier)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_app_user_local_identifier');
        $this->addSql('ALTER TABLE app_user ALTER npub SET NOT NULL');
        $this->addSql('ALTER TABLE app_user DROP local_identifier');
    }
}
