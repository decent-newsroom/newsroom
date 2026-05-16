<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Change media_post_cache.kind from SMALLINT to INTEGER.
 *
 * Nostr event kinds are unsigned integers that can exceed the SMALLINT
 * maximum of 32 767 (e.g. kind 34235 for media discovery events).
 */
final class Version20260516090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Change media_post_cache.kind from SMALLINT to INTEGER to support all Nostr event kinds';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE media_post_cache ALTER COLUMN kind TYPE INT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE media_post_cache ALTER COLUMN kind TYPE SMALLINT');
    }
}

