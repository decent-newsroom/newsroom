<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261001125234 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Purge retired feed expression and spell events';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DELETE FROM event WHERE kind IN (777, 30880)');
    }

    public function down(Schema $schema): void
    {
        // Purged Nostr events cannot be restored.
    }
}
