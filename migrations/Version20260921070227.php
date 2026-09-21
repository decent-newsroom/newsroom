<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260921070227 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Restore event tag containment and featured reading-list reference indexes';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_event_tags_gin ON event USING GIN (tags jsonb_path_ops)');
        $this->addSql("CREATE INDEX IF NOT EXISTS idx_parsed_ref_article_target ON parsed_reference (target_kind, target_pubkey, source_event_id) WHERE tag_name = 'a' AND target_kind IN (30023, 30024)");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_parsed_ref_article_target');
        $this->addSql('DROP INDEX IF EXISTS idx_event_tags_gin');
    }
}
