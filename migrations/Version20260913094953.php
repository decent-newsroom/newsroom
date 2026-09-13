<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260913094953 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Persist local Unfold presentation settings by permanent root magazine coordinate';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE unfold_publication_settings (coordinate VARCHAR(500) NOT NULL, theme VARCHAR(255) NOT NULL, PRIMARY KEY (coordinate))');
        // Preserve current rendering defaults; never infer settings from old AppData events.
        // Normalize only the pubkey. D-tags are case-sensitive and may contain colons.
        $this->addSql(<<<'SQL'
            INSERT INTO unfold_publication_settings (coordinate, theme)
            SELECT DISTINCT '30040:' || lower(substring(coordinate from 7 for 64)) || substring(coordinate from 71), 'default'
            FROM unfold_site
            WHERE coordinate ~ '^30040:[a-fA-F0-9]{64}:.+$'
            ON CONFLICT (coordinate) DO NOTHING
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE unfold_publication_settings');
    }
}
