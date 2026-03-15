<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add lastMetadataRefresh and lastLoginAt columns to app_user.
 * Used for throttling profile refresh worker and login sync chain.
 */
final class Version20260315120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add last_metadata_refresh and last_login_at columns to app_user for worker throttling';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user ADD last_metadata_refresh TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE app_user ADD last_login_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('COMMENT ON COLUMN app_user.last_metadata_refresh IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN app_user.last_login_at IS \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user DROP last_metadata_refresh');
        $this->addSql('ALTER TABLE app_user DROP last_login_at');
    }
}

