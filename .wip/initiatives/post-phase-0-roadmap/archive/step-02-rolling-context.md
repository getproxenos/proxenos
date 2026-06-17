# Step 02 — Rolling Context (Track A · core.document vertical spine)

**Coordinator**: post-phase-0-roadmap-step-02-coordinator (Solo proc 542)
**Researcher**: NONE spawned — see Decisions §0. Workplan is complete; design questions escalate to Orchestrator (proc 512).
**Orchestrator**: proc 512
**Workplan**: .wip/initiatives/post-phase-0-roadmap/workplans/step-02-track-a-vertical-spine-on-core-document.md
**Branch**: step-02/core-document-spine (stacked on step-01/model-profile-taxonomy)
**Build started**: 2026-06-16

## Scope (chunks 5–9; 1–4 already committed pre-orchestration)
- 5. HTTP CRUD: POST /api/documents, GET /api/documents/:id
- 6. Attach/pin: thread_entity_attached/detached events + payloads + ThreadAttachment projection + ThreadAttachmentService
- 7. Entity-aware prompt assembly (PromptAssembler + PromptContribution + ContextBudgetPlanner) replacing ChatRespondLoop dumb concat
- 8. HTTP CRUD attach/detach endpoints + end-to-end functional test
- 9. ADR-027b "core.document spine" ADR + ADR-012 escape-hatch evidence list

## Batching plan
Sequential, one Builder per chunk (5→6→7→8→9). Chunks are ordered dependencies:
7 depends on 6's projection/service; 8 depends on 5+6+7. No parallelism — single stacked branch.

> Live task status: query ledger by tag `post-phase-0-roadmap/step-02`.

## Decisions made during build

### §0 No separate Researcher process (2026-06-16)
Coordinator spawned mid-stream by Orchestrator with chunks 1–4 already built and a
complete workplan carrying a "lean" for every open question. Orchestrator's bootstrap
instructs: drive chunks 5–9 via Builders, escalate design questions to proc 512.
Treating the workplan as the research artifact and the Orchestrator as the design-consult
hub. If a Builder hits a genuine spec gap, escalate to 512 rather than spawning a Researcher.

## Escalations
(none yet)

## Per-task outcomes
(append one paragraph per chunk completion)


### Chunk 5 outcome (2026-06-16) — Builder 05 (proc 543), commit 85525ca
HTTP CRUD landed: ApiDocumentController POST/GET /api/documents → {id, envelope, data}; 404 not_found; tenant-scoped via findOneByIdForTenant; CSRF on shared 'chat' intention; lightweight in-controller validation (required+additionalProperties, entity enforces lengths); persist+flush via injected EM (no repo save()). Functional test covers happy POST/GET + 404. Gates: make test 100/500 OK, make lint green. Pushed. Gotcha: stale test container router cache on first run — cleared var/cache/test. Decisions applied: top-level id included, no JSON-schema lib, PATCH out of scope. NO escalation.

### Chunk 6 outcome (2026-06-16) — Builder 06 (proc 544), commit 8738108
Attach/pin landed & GREEN: 2 ConversationEventType cases, ThreadEntityAttached/Detached payloads, ThreadAttachment projection entity (composite PK thread_id+provider+type+entity_id; entity_id opaque varchar; stores full reference jsonb + tenant_id/attached_at/last_sequence) + repo, ThreadAttachmentService(attach/detach/listForThread in attach order), ProjectionFolder folds (attach upsert + last_sequence guard; detach DELETE no-op-if-absent), ProjectionsRebuildCommand truncate-list updated (gotcha 1), unit + functional fold/rebuild/idempotency/service tests. make test 110/533 OK, make lint green, migration applied to dev + test DBs.

ESCALATION (see below): Builder followed literal `git add -A` and swept ~24 PRE-EXISTING untracked files into commit 8738108 (unrelated to step-02). Slimming needs force-push (policy-blocked for builder). Escalated to Orchestrator 512. PROCESS FIX going forward: all remaining builders get explicit `git add <paths>` instead of `git add -A`.

## Escalations
- [ESCALATION step-02/builder-06] Commit 8738108 polluted with ~24 pre-existing untracked files via git add -A. Needs: (a) force-push authorization to rewrite 8738108 → only the 12 chunk-6 files, and (b) disposition decision for the pre-existing untracked files. Raised to Orchestrator 512 @ 2026-06-16. Chunk 7 spawning PAUSED pending answer.

### Escalation RESOLVED (2026-06-16) — chunk 6 commit hygiene
Beau approved force-push. Executed: rm generic.patch; git reset 85525ca; staged only the 12 chunk-6 paths; recommit (same message) → 5b96388 (replaces 8738108); push --force-with-lease. Other untracked in-flight files (.claude-plugin/, agents/, commands/, engineering/, current-handoffs/{bundle,handoff-intake-bundle-kind}.md, CHANGELOG.md, cliff.toml, FUNCTIONAL-TEST-PLAN.md) left untouched per Beau. Post-rewrite: make test 110/533 OK, make lint clean. Chunk 6 DONE. Pipeline resumed → chunk 7. PROCESS: builders 7–9 use explicit `git add <paths>`.

### Chunk 7 outcome (2026-06-16) — Builder 07 (proc 545), commit 511039f
Entity-aware prompt assembly landed & CLEAN (hygiene gate passed — 9 files, no leakage). make test 121/573, make lint green; existing ChatRespondLoop tests unchanged. Files: PromptContribution{weight,role,text}, ContextBudgetPlanner (attached_entities full→summary→reference ladder, floor(strlen/4), 4000 default; transclusions no-op seam kept per guardrail), AttachmentCandidate/AdmittedEntity VOs, PromptAssembler (CONTRACT-OUT assemble(threadId,tenantId); pipeline listForThread→EntityResolver→expansion slot [pill honored; summary/full→pill w/ debug log, routed NOT hardcoded]→EntityRenderer→budget→one system fragment; dangling skipped; []-when-empty; pure assembleResolved() core for testability since collaborators are final), ChatRespondLoop wired (PlatformMessage::forSystem ahead of history, weight-sorted; streaming/ADR-025/token-usage untouched). Cross-lane: current-handoffs/handoff-prompt-contribution-contract.md documents shape + [systemPrompt,entityContext,conversationHistory] ordering; Lane D thumbs-up required before merge to main (NON-BLOCKING, captured). Key learning: correct API is PlatformMessage::forSystem (not ofSystem). NO escalation. Explicit-git-add rule held.

### Chunk 8 outcome (2026-06-16) — Builder 08 (proc 546), commit 3db2cdc
Attach/detach endpoints + MARQUEE e2e shipped CLEAN (2 files, no leakage). make test 125/590, make lint green. ApiThreadAttachmentController: POST/DELETE /api/threads/{id}/attachments (ApiChatController auth mirror; attach→Reference VO, expansion default pill, 201 echo, 400 on missing triple; detach→triple from body/query, 204, idempotent). E2E EntityAwareTurnTest: create core.document → attach (pill) to fresh thread → fire turn vs RecordingInMemoryPlatform → assert captured SystemMessage has doc title "Quarterly OKRs" + UserMessage has user text + assistant Message COMPLETE. The full spine (declare→store→resolve→render→attach→assemble→budget→loop→stream) now proven end-to-end. NO escalation. Explicit-git-add held.

### Chunk 9 — HELD at boundary pending Orchestrator answer
Asked Orchestrator (non-urgent, parallel during chunk 8): where does ADR-027b live given workplan says engineering/decisions/ but Beau fenced off the untracked engineering/ tree ("do not touch")? Options a(=write one ADR file into engineering/decisions/, my lean) / b(Beau tracks engineering/ first) / c(park ADR in already-tracked dir). NOT spawning Builder 09 until answered. After chunk 9 verified: do NOT auto-open PR — hand the DRAFT PR (base step-01/model-profile-taxonomy, head step-02/core-document-spine) to the Orchestrator as the step-boundary action.

### Chunk 9 outcome (2026-06-16) — Builder 09 (proc 547), commit 2aae18b
ADR-027b shipped CLEAN (1 file, no leakage) at .wip/initiatives/post-phase-0-roadmap/decisions/ADR-027b-core-document-spine.md per Beau's option (c) + relocate note. make lint green. Records 10 step-02 decisions + ADR-012 escape-hatch evidence list (first entry: renderer held end-to-end). 

## STEP-02 BUILD COMPLETE (2026-06-16)
All 9 chunks shipped + green on step-02/core-document-spine. Chunk→commit: 1–4 (pre-orchestration, 3ea0701/13d3191/7a7de9c/480ea05), 5=85525ca, 6=5b96388 (force-push-slimmed from 8738108), 7=511039f, 8=3db2cdc, 9=2aae18b. Final test count at chunk 8: make test 125/590; make lint green throughout. Full vertical spine (declare→store→resolve→render→attach→assemble→budget→loop→stream) proven end-to-end by EntityAwareTurnTest. 
NEXT (Orchestrator/step-boundary): open DRAFT PR base=step-01/model-profile-taxonomy head=step-02/core-document-spine; PR stays DRAFT until Beau /code-review; then code-review→fix loop. PRE-MERGE GATE: Lane D thumbs-up on PromptContribution shape (current-handoffs/handoff-prompt-contribution-contract.md). Workplan + this note NOT yet archived — defer until step truly ships (post-review/merge).

## Post-build retro — step-02 chunks 5–9 (2026-06-16)

**Outcome:** 5/5 in-scope chunks shipped green, first-commit-pass on every chunk (zero builder retries; no gate ever failed on a builder's own commit). DRAFT PRs opened by Orchestrator: #14 step-01→main, #15 step-02→step-01.

**Timings (approx bake time):** c5 quick (~2 wakes); c6 longest ~13m (migration+projection+service+rebuild-idempotency, several premature idles); c7 ~12m (deep research first — chose a testable pure-core design around final collaborators); c8 ~4m; c9 ~2m (doc-only).

**Retries:** 0. **Escalations:** 2, both resolved cleanly —
1. Chunk-6 `git add -A` swept ~24 unrelated pre-existing untracked files into the commit. Root cause: MY chunk-5/6 bootstrap used a literal `git add -A`. Beau approved a force-push; I slimmed 8738108→5b96388 to the 12 chunk-6 files. FIX: switched builders 7–9 to explicit `git add <paths>` + added a `git show --stat HEAD` hygiene self-check in each bootstrap and each wake verification. Zero recurrence (c7/c8/c9 all clean).
2. Chunk-9 ADR location: workplan said engineering/decisions/ but Beau had fenced off the untracked engineering/ tree. Surfaced proactively & in parallel during c8 (non-blocking). Beau chose option (c): park ADR in already-tracked .wip/.../decisions/ with a relocate note.

**Hygiene incidents:** 1 (the chunk-6 leak above). Caught at the verify gate, not in the PR.

**Premature-idle handling:** builders micro-pause constantly; idle timers fired prematurely many times (c6 ~4 wakes, c7 ~4 wakes). Discipline that paid off: NEVER trusted an idle fire — always verified (new commit exists ∧ pushed ∧ `git show --stat` hygiene ∧ gates green in the builder's own summary) before advancing; re-armed on micro-pause. No false "done" ever propagated.

**Code learnings worth carrying:** PlatformMessage::forSystem (not ofSystem) for system segments; test-DB schema needs `APP_ENV=test php bin/console doctrine:migrations:migrate`; clear var/cache/test after adding a controller/entity in the test env.

**What I'd change:** put the explicit-`git add <paths>` rule in the FIRST builder bootstrap, not retrofit it after a leak. The literal `git add -A` in my early bootstraps was the single avoidable defect of the run.