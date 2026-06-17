<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Step-02 chunk 6 — `thread_attachments` projection table (decision 6).
 *
 * Folded from the `thread_entity_attached` / `thread_entity_detached`
 * conversation events; the event log itself needs no migration (its `type` is
 * a varchar). Composite PK is the thread plus the reference identity triple
 * (`provider` + `type` + `entity_id`); `entity_id` is the OPAQUE reference id,
 * a plain varchar (non-host providers won't mint uuids). `reference` stores the
 * full ADR-013a envelope so the projection can reconstruct it byte-faithfully.
 *
 * Hand-authored to keep column types / index / FK names deterministic, mirroring
 * Version20260616000000.
 */
final class Version20260616000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Step-02: thread_attachments projection for event-sourced attach/pin.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE thread_attachments (
                thread_id     UUID                        NOT NULL,
                provider      VARCHAR(64)                 NOT NULL,
                type          VARCHAR(128)                NOT NULL,
                entity_id     VARCHAR(255)                NOT NULL,
                tenant_id     UUID                        NOT NULL,
                reference     JSONB                       NOT NULL,
                attached_at   TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                last_sequence BIGINT                      NOT NULL,
                PRIMARY KEY (thread_id, provider, type, entity_id)
            )
            SQL);
        $this->addSql('CREATE INDEX idx_thread_attachments_tenant_thread ON thread_attachments (tenant_id, thread_id)');
        $this->addSql(<<<'SQL'
            ALTER TABLE thread_attachments
                ADD CONSTRAINT fk_thread_attachments_tenant
                    FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS thread_attachments');
    }
}
