<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20241211164528 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE nzine (id INT GENERATED BY DEFAULT AS IDENTITY NOT NULL, npub VARCHAR(255) NOT NULL, main_categories JSON NOT NULL, lists JSON DEFAULT NULL, editor VARCHAR(255) DEFAULT NULL, nzine_bot_id INT DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_65025D9871FD5427 ON nzine (nzine_bot_id)');
        $this->addSql('CREATE TABLE nzine_bot (id INT GENERATED BY DEFAULT AS IDENTITY NOT NULL, nsec VARCHAR(255) NOT NULL, PRIMARY KEY(id))');
        $this->addSql('ALTER TABLE nzine ADD CONSTRAINT FK_65025D9871FD5427 FOREIGN KEY (nzine_bot_id) REFERENCES nzine_bot (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('DROP TABLE journal');
        $this->addSql('ALTER TABLE app_user ADD nzine_bot_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE app_user ADD CONSTRAINT FK_88BDF3E971FD5427 FOREIGN KEY (nzine_bot_id) REFERENCES nzine_bot (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_88BDF3E971FD5427 ON app_user (nzine_bot_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE journal (id INT GENERATED BY DEFAULT AS IDENTITY NOT NULL, npub VARCHAR(255) NOT NULL, main_categories JSON NOT NULL, lists JSON DEFAULT NULL, editor VARCHAR(255) DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('ALTER TABLE nzine DROP CONSTRAINT FK_65025D9871FD5427');
        $this->addSql('DROP TABLE nzine');
        $this->addSql('DROP TABLE nzine_bot');
        $this->addSql('ALTER TABLE app_user DROP CONSTRAINT FK_88BDF3E971FD5427');
        $this->addSql('DROP INDEX UNIQ_88BDF3E971FD5427');
        $this->addSql('ALTER TABLE app_user DROP nzine_bot_id');
    }
}
