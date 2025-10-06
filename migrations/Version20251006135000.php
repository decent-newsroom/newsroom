<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251006135000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            DROP INDEX idx_article_slug
        SQL);
        $this->addSql(<<<'SQL'
            DROP INDEX idx_3bae0aa7afece2eb
        SQL);
        $this->addSql(<<<'SQL'
            DROP INDEX idx_event_kind_created
        SQL);
        $this->addSql(<<<'SQL'
            DROP INDEX idx_event_pubkey
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE visit ADD session_id VARCHAR(255) DEFAULT NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_article_slug ON article (slug)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE visit DROP session_id
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_3bae0aa7afece2eb ON event (kind)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_event_kind_created ON event (kind, created_at)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_event_pubkey ON event (pubkey)
        SQL);
    }
}
