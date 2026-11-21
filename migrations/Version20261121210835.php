<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261121210835 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Dossiers: one AG-UI thread, its conversation and its research state, per user.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE dossier (created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, title VARCHAR(160) NOT NULL, phase VARCHAR(32) NOT NULL, state CLOB NOT NULL, messages CLOB NOT NULL, interrupts CLOB NOT NULL, id VARCHAR(64) NOT NULL, owner VARCHAR(180) NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX dossier_owner ON dossier (owner, updated_at)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE dossier');
    }
}
