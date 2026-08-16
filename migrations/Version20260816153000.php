<?php

declare(strict_types=1);

namespace DoctrineMigrations\Integration;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Hotfix schema drift: tenants with pre-baseline `integration` table lack columns
 * declared in Version20260714190000 / Entity\Integration (retry etc.).
 * Idempotent ALTER so already-aligned tenants are unaffected.
 *
 * Related: ControleOnline/agents-mcp#110
 */
final class Version20260816153000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add missing columns on integration table for schema-drifted tenants (retry and correlatas)';
    }

    public function up(Schema $schema): void
    {
        // MySQL 8+ / MariaDB support IF NOT EXISTS on ADD COLUMN in recent versions;
        // use procedural check for broader compatibility.
        $this->addSql("SET @dbname = DATABASE()");
        $this->addSql("SET @tablename = 'integration'");
        $this->addSql("SET @columnname = 'retry'");
        $this->addSql("SET @preparedStatement = (SELECT IF(
          (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
           WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = @columnname) > 0,
          'SELECT 1',
          'ALTER TABLE `integration` ADD COLUMN `retry` int(11) NOT NULL DEFAULT 0'
        ))");
        $this->addSql("PREPARE alterIfNotExists FROM @preparedStatement");
        $this->addSql("EXECUTE alterIfNotExists");
        $this->addSql("DEALLOCATE PREPARE alterIfNotExists");
    }

    public function down(Schema $schema): void
    {
        // irreversible safety for production tenants
        return;
    }
}
