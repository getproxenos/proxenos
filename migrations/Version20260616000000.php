<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Step-02 part 1 — host-stored `core.document` instances (ADR-013/ADR-017).
 *
 * The document is NOT event-sourced — it's a plain CRUD row. The
 * conversation event log only records attach/detach (see the follow-up
 * migration). `tags` is a JSONB array of strings.
 *
 * Hand-authored so column types, indexes, and FK names stay deterministic
 * — the rest of the schema in this project follows the same convention.
 */
final class Version20260616000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Step-02: core_documents host-storage table for the core.document baseline.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE core_documents (
                id                  UUID                        NOT NULL,
                tenant_id           UUID                        NOT NULL,
                created_by_user_id  UUID                        NULL,
                title               VARCHAR(200)                NOT NULL,
                body                TEXT                        NOT NULL,
                tags                JSONB                       NOT NULL DEFAULT '[]',
                collection          VARCHAR(200)                NULL,
                created_at          TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                updated_at          TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
            SQL);
        $this->addSql('CREATE INDEX idx_core_documents_tenant ON core_documents (tenant_id)');
        $this->addSql('CREATE INDEX idx_core_documents_collection ON core_documents (tenant_id, collection)');
        $this->addSql(<<<'SQL'
            ALTER TABLE core_documents
                ADD CONSTRAINT fk_core_documents_tenant
                    FOREIGN KEY (tenant_id)          REFERENCES tenants (id) ON DELETE CASCADE,
                ADD CONSTRAINT fk_core_documents_created_by_user
                    FOREIGN KEY (created_by_user_id) REFERENCES users   (id) ON DELETE SET NULL
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS core_documents');
    }
}
