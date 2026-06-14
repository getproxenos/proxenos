<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Phase 0.0 initial migration. Enables pgvector now even though Phase 0 stores no
 * embeddings — it's a known future need (in-core embeddings / Store, ADR-016
 * neighborhood) and baking it in now avoids a later infra change. The DB image is
 * pgvector/pgvector:pg16, which ships the extension.
 */
final class Version20260613000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Enable the pgvector extension.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE EXTENSION IF NOT EXISTS vector');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP EXTENSION IF EXISTS vector');
    }
}
