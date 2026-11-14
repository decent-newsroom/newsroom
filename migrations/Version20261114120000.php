<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261114120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store exact event-ID references and relay hints in parsed_reference';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE parsed_reference ADD target_event_id VARCHAR(64)');
        $this->addSql('ALTER TABLE parsed_reference ADD relay_hint TEXT');
        $this->addSql("CREATE INDEX idx_parsed_ref_target_event_id ON parsed_reference (target_event_id) WHERE target_event_id IS NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_parsed_ref_target_event_id');
        $this->addSql('ALTER TABLE parsed_reference DROP relay_hint');
        $this->addSql('ALTER TABLE parsed_reference DROP target_event_id');
    }
}
