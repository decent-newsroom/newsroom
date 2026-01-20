<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260120164607 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add index on kind column in Event table for improved media event queries';
    }

    public function up(Schema $schema): void
    {
        // Add index on kind column for efficient media event queries (kinds 20, 21, 22)
        $this->addSql('CREATE INDEX idx_event_kind ON event (kind)');
    }

    public function down(Schema $schema): void
    {
        // Remove the kind index
        $this->addSql('DROP INDEX idx_event_kind ON event');
    }
}
