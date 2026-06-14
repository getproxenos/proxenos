<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Phase 0.2 — event-sourced conversation persistence (ADR-004, ADR-022).
 *
 * Adds the canonical `conversation_events` log plus the four projection
 * tables (`threads`, `turns`, `messages`, `message_parts`) the v0 turn loop
 * folds into. Schema mirrors design-notes/event-sourced-conversations.md §2/§4
 * with one rename: the doc's `workspace_id` is implemented as `tenant_id`
 * (ADR-021 — every later table inherits tenancy by carrying `tenant_id`).
 *
 * `branch_id` ships nullable + unused per the doc; idempotency_key likewise.
 * Hand-authored (not `doctrine:migrations:diff`) so column types, JSONB, and
 * constraint names stay deterministic.
 */
final class Version20260615000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 0.2: conversation_events + threads/turns/messages/message_parts projections.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE conversation_events (
                id               UUID                        NOT NULL,
                tenant_id        UUID                        NOT NULL,
                thread_id        UUID                        NOT NULL,
                branch_id        UUID                        NULL,
                turn_id          UUID                        NULL,
                sequence         BIGINT                      NOT NULL,
                type             VARCHAR(64)                 NOT NULL,
                version          INT                         NOT NULL DEFAULT 1,
                actor_type       VARCHAR(32)                 NOT NULL,
                actor_id         VARCHAR(255)                NULL,
                occurred_at      TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                correlation_id   UUID                        NULL,
                causation_id     UUID                        NULL,
                idempotency_key  VARCHAR(255)                NULL,
                payload          JSONB                       NOT NULL,
                redaction_state  VARCHAR(16)                 NOT NULL DEFAULT 'normal',
                PRIMARY KEY (id)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_conversation_events_thread_sequence ON conversation_events (thread_id, sequence)');
        $this->addSql('CREATE INDEX idx_conversation_events_tenant ON conversation_events (tenant_id)');
        $this->addSql('CREATE INDEX idx_conversation_events_turn   ON conversation_events (turn_id)');
        $this->addSql(<<<'SQL'
            ALTER TABLE conversation_events
                ADD CONSTRAINT fk_conversation_events_tenant
                    FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE threads (
                id                  UUID                        NOT NULL,
                tenant_id           UUID                        NOT NULL,
                created_by_user_id  UUID                        NULL,
                title               VARCHAR(200)                NULL,
                status              VARCHAR(16)                 NOT NULL DEFAULT 'active',
                last_sequence       BIGINT                      NOT NULL DEFAULT 0,
                created_at          TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                updated_at          TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
            SQL);
        $this->addSql('CREATE INDEX idx_threads_tenant ON threads (tenant_id)');
        $this->addSql(<<<'SQL'
            ALTER TABLE threads
                ADD CONSTRAINT fk_threads_tenant
                    FOREIGN KEY (tenant_id)          REFERENCES tenants (id) ON DELETE CASCADE,
                ADD CONSTRAINT fk_threads_created_by_user
                    FOREIGN KEY (created_by_user_id) REFERENCES users (id)   ON DELETE SET NULL
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE turns (
                id              UUID                        NOT NULL,
                thread_id       UUID                        NOT NULL,
                tenant_id       UUID                        NOT NULL,
                status          VARCHAR(16)                 NOT NULL,
                last_sequence   BIGINT                      NOT NULL,
                created_at      TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                completed_at    TIMESTAMP(0) WITH TIME ZONE NULL,
                PRIMARY KEY (id)
            )
            SQL);
        $this->addSql('CREATE INDEX idx_turns_thread ON turns (thread_id)');
        $this->addSql(<<<'SQL'
            ALTER TABLE turns
                ADD CONSTRAINT fk_turns_thread
                    FOREIGN KEY (thread_id) REFERENCES threads (id) ON DELETE CASCADE
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE messages (
                id              UUID                        NOT NULL,
                thread_id       UUID                        NOT NULL,
                turn_id         UUID                        NULL,
                tenant_id       UUID                        NOT NULL,
                role            VARCHAR(16)                 NOT NULL,
                status          VARCHAR(16)                 NOT NULL,
                position        INT                         NOT NULL,
                last_sequence   BIGINT                      NOT NULL,
                created_at      TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                completed_at    TIMESTAMP(0) WITH TIME ZONE NULL,
                PRIMARY KEY (id)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_messages_thread_position ON messages (thread_id, position)');
        $this->addSql('CREATE INDEX idx_messages_thread ON messages (thread_id)');
        $this->addSql('CREATE INDEX idx_messages_turn   ON messages (turn_id)');
        $this->addSql(<<<'SQL'
            ALTER TABLE messages
                ADD CONSTRAINT fk_messages_thread
                    FOREIGN KEY (thread_id) REFERENCES threads (id) ON DELETE CASCADE,
                ADD CONSTRAINT fk_messages_turn
                    FOREIGN KEY (turn_id)   REFERENCES turns   (id) ON DELETE SET NULL
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE message_parts (
                id           UUID                        NOT NULL,
                message_id   UUID                        NOT NULL,
                thread_id    UUID                        NOT NULL,
                tenant_id    UUID                        NOT NULL,
                position     INT                         NOT NULL,
                kind         VARCHAR(32)                 NOT NULL,
                content      TEXT                        NOT NULL,
                created_at   TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_message_parts_message_position ON message_parts (message_id, position)');
        $this->addSql(<<<'SQL'
            ALTER TABLE message_parts
                ADD CONSTRAINT fk_message_parts_message
                    FOREIGN KEY (message_id) REFERENCES messages (id) ON DELETE CASCADE
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS message_parts');
        $this->addSql('DROP TABLE IF EXISTS messages');
        $this->addSql('DROP TABLE IF EXISTS turns');
        $this->addSql('DROP TABLE IF EXISTS threads');
        $this->addSql('DROP TABLE IF EXISTS conversation_events');
    }
}
