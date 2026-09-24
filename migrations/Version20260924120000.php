<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;

final class Version20260924120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove Active Indexing subscriptions and its user role';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE app_user AS u
            SET roles = (
                SELECT COALESCE(json_agg(r.role ORDER BY r.ordinality), '[]'::json)
                FROM json_array_elements_text(u.roles) WITH ORDINALITY AS r(role, ordinality)
                WHERE r.role <> 'ROLE_ACTIVE_INDEXING'
            )
            WHERE u.roles IS NOT NULL
              AND jsonb_exists(u.roles::jsonb, 'ROLE_ACTIVE_INDEXING')
            SQL);
        $this->addSql('DROP TABLE active_indexing_subscription');
    }

    public function down(Schema $schema): void
    {
        throw new IrreversibleMigration('Deleted Active Indexing subscriptions and role assignments cannot be restored.');
    }
}
