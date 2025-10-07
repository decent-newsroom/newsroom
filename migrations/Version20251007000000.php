<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add RSS feed support to Nzine entity
 */
final class Version20251007000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add RSS feed URL, last fetched timestamp, and feed configuration to nzine table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE nzine ADD feed_url TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE nzine ADD last_fetched_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE nzine ADD feed_config JSON DEFAULT NULL');
        $this->addSql('COMMENT ON COLUMN nzine.last_fetched_at IS \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE nzine DROP feed_url');
        $this->addSql('ALTER TABLE nzine DROP last_fetched_at');
        $this->addSql('ALTER TABLE nzine DROP feed_config');
    }
}
