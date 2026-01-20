<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Create magazine table for storing projected magazine data
 */
final class Version20260121000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create magazine table for storing projected magazine indices from Nostr events';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('
            CREATE TABLE magazine (
                id SERIAL PRIMARY KEY,
                slug VARCHAR(255) NOT NULL UNIQUE,
                title VARCHAR(500),
                summary TEXT,
                image VARCHAR(500),
                language VARCHAR(10),
                tags JSON,
                categories JSON,
                contributors JSON,
                relay_pool JSON,
                contained_kinds JSON,
                pubkey VARCHAR(64),
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                published_at TIMESTAMP
            )
        ');

        $this->addSql('CREATE INDEX idx_magazine_slug ON magazine (slug)');
        $this->addSql('CREATE INDEX idx_magazine_created_at ON magazine (created_at DESC)');
        $this->addSql('CREATE INDEX idx_magazine_published_at ON magazine (published_at DESC)');
        $this->addSql('CREATE INDEX idx_magazine_pubkey ON magazine (pubkey)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS magazine');
    }
}
