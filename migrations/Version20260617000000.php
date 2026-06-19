<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Step-03 chunk D9 — system prompt v0 (decision 5).
 *
 * Two nullable TEXT columns:
 *   - `users.system_prompt_default` — the per-user global default system prompt.
 *   - `threads.system_prompt`       — the per-thread override (folded from
 *     `thread_system_prompt_set`; the event log itself needs no migration, its
 *     `type` is a varchar).
 *
 * The effective prompt is `thread.system_prompt ?? user.system_prompt_default`
 * (blank → none), resolved by {@see \App\Ai\Chat\SystemPromptResolver}.
 *
 * Hand-authored to keep column types deterministic, mirroring
 * Version20260616000001.
 */
final class Version20260617000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Step-03 D9: system prompt v0 — users.system_prompt_default + threads.system_prompt.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD system_prompt_default TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE threads ADD system_prompt TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE threads DROP COLUMN IF EXISTS system_prompt');
        $this->addSql('ALTER TABLE users DROP COLUMN IF EXISTS system_prompt_default');
    }
}
