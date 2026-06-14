<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Phase 0.1 — identity + tenancy: tenants, users, memberships (ADR-020/021).
 * UUIDv7 ids stored as Postgres `uuid`; timestamps as TIMESTAMP(0) WITH TIME
 * ZONE. Hand-authored (not `doctrine:migrations:diff`) so column types and
 * constraint names stay deterministic.
 */
final class Version20260614000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 0.1: tenants, users, memberships (UUIDv7 ids).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE tenants (
                id          UUID         NOT NULL,
                slug        VARCHAR(64)  NOT NULL,
                name        VARCHAR(200) NOT NULL,
                created_at  TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_tenants_slug ON tenants (slug)');

        $this->addSql(<<<'SQL'
            CREATE TABLE users (
                id             UUID         NOT NULL,
                email          VARCHAR(254) NOT NULL,
                password_hash  VARCHAR(255) NOT NULL,
                created_at     TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_users_email ON users (email)');

        $this->addSql(<<<'SQL'
            CREATE TABLE memberships (
                id          UUID        NOT NULL,
                user_id     UUID        NOT NULL,
                tenant_id   UUID        NOT NULL,
                role        VARCHAR(32) NOT NULL,
                created_at  TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_memberships_user_tenant ON memberships (user_id, tenant_id)');
        $this->addSql('CREATE INDEX idx_memberships_tenant ON memberships (tenant_id)');
        $this->addSql(<<<'SQL'
            ALTER TABLE memberships
                ADD CONSTRAINT fk_memberships_user
                    FOREIGN KEY (user_id)   REFERENCES users (id)   ON DELETE CASCADE,
                ADD CONSTRAINT fk_memberships_tenant
                    FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS memberships');
        $this->addSql('DROP TABLE IF EXISTS users');
        $this->addSql('DROP TABLE IF EXISTS tenants');
    }
}
