<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260925120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Index visit route and time for exact-path analytics lookup';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_visit_route_visited_at ON visit (route, visited_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_visit_route_visited_at');
    }
}
