<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260302153359 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX idx_event_tags_gin');
        $this->addSql('ALTER TABLE event ALTER tags TYPE JSON');
        $this->addSql('ALTER TABLE visit ADD referer VARCHAR(2048) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE event ALTER tags TYPE JSONB');
        $this->addSql('CREATE INDEX idx_event_tags_gin ON event (tags)');
        $this->addSql('ALTER TABLE visit DROP referer');
    }
}
