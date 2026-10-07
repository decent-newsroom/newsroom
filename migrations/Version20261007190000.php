<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261007190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add persistent instance-wide author ingestion bans, separate from NIP-09 tombstones.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE banned_pubkey (pubkey VARCHAR(64) NOT NULL, reason TEXT NOT NULL, added_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, added_by VARCHAR(255) NOT NULL, PRIMARY KEY(pubkey))');
        // Also protect DBAL writes and workers already in flight when a ban is committed.
        $this->addSql(<<<'SQL'
CREATE FUNCTION reject_banned_author_content() RETURNS trigger AS $$
BEGIN
    IF EXISTS (SELECT 1 FROM banned_pubkey WHERE pubkey = LOWER(NEW.pubkey)) THEN
        RAISE EXCEPTION 'Content author is banned by this instance' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql
SQL);
        foreach (['article', 'event', 'highlight', 'magazine', 'current_record'] as $table) {
            $this->addSql(sprintf('CREATE TRIGGER reject_banned_author BEFORE INSERT OR UPDATE OF pubkey ON %s FOR EACH ROW EXECUTE FUNCTION reject_banned_author_content()', $table));
        }
    }

    public function down(Schema $schema): void
    {
        foreach (['article', 'event', 'highlight', 'magazine', 'current_record'] as $table) {
            $this->addSql(sprintf('DROP TRIGGER reject_banned_author ON %s', $table));
        }
        $this->addSql('DROP FUNCTION reject_banned_author_content()');
        $this->addSql('DROP TABLE banned_pubkey');
    }
}
