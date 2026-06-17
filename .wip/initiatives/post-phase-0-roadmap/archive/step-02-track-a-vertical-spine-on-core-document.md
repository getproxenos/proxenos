# Workplan — step-02 · Track A — Vertical spine on `core.document`

Build the first vertical cut through the typed-context layer against a
host-native `core.document` entity: typed entity → schema render → reference
resolution → attach → entity-aware prompt assembly → budget → existing turn
loop → stream. Proves the product thesis end-to-end **without opening the
ADR-010 process boundary**. Hypomnema (part 2) is deliberately deferred.

Roadmap entry: `.wip/initiatives/post-phase-0-roadmap/roadmap.md` (Lane A, step-02).
Handoff: `current-handoffs/handoff-core-document-spine.md`.

Started: 2026-06-16.

## Decisions (made here, feed later steps)

1. **`core.document` is the only typed entity in this slice.** No second type,
   no second provider, no `core.memory`. The point is to prove the
   resolve/render/attach/serialize seams once, end-to-end.

2. **Type declaration is a host-side, in-process value object.** A
   `TypedEntityDeclaration` produces the ADR-013 envelope
   (`type`/`type_version`/`provider`/`schema`/`presentation`/`capabilities`)
   as plain PHP data. No registry yet (ADR-014 is F2); the renderer and the
   resolver dispatch on `provider+type` from the **envelope object**, not
   from a registry. The shape is registry-adoptable later.

3. **Instances live in a Doctrine entity (`CoreDocument`).** Host-stored per
   ADR-017 baseline. Fields: `id` (uuid v7), `tenantId`, `title`, `body`,
   `tags` (jsonb), `collection` (string, nullable), `createdAt`, `updatedAt`,
   `createdByUserId`. **No event-sourcing for the document itself** in this
   slice — documents are CRUD, not a projection. (Attach/pin **is**
   event-sourced; see decision 6.) Rationale: ADR-017's host-storage baseline
   does not require the document body to be a fold of events; the
   conversation log is the part that has to be replayable.

4. **Reference envelope is a value object (`Reference`)** matching ADR-013a
   fields: `provider`, `type`, `id`, `resolved`, optional `marker`, `label`,
   `expansion` (`pill`|`summary`|`full`; default `pill`), `snapshot`,
   `target`. `id` is opaque — host compares by byte-string. The resolver
   returns a `ResolvedReference { reference, instance|null, sidecar }`;
   `instance === null` means dangling.

5. **Schema-driven renderer is a pure function.** Input: envelope
   (`schema`+`presentation`) + instance data. Output: a
   `RenderedEntity { kind: 'card'|'pill', title, summary?, fields,
   icon?, contentTypes }` PHP DTO. No HTML; no provider-specific code. Track
   D (SPA) consumes the DTO. **No custom_renderer escape hatch** in this
   slice; start an evidence list in the ADR.

6. **Attach/pin is event-sourced.** Two new conversation events on the
   existing log:
   - `thread_entity_attached` — payload: `{ reference: <envelope>, attachedAt? }`
   - `thread_entity_detached` — payload: `{ provider, type, id }`
     (byte-equality of envelope's identity triple)
   Folded into a new `thread_attachments` projection table (composite key
   `thread_id + provider + type + id`, plus `attached_at`, `last_sequence`).
   New `ConversationEventType` cases; storage is varchar so no schema
   migration on the event log itself.

7. **Entity-aware prompt assembly is a small orchestrator** wired into
   `ChatRespondLoop` in place of the dumb projection-only concat. Pipeline:
   `(thread) → attachments projection → resolver → expansion-policy slot
   (`pill` honored, `summary`/`full` silently downgraded with a debug log) →
   budget admission → serialized fragment → MessageBag`. The fragment is
   prepended as a **system message** segment, ahead of the conversation
   history. Cross-cut with Lane D: the *ordered-contributions contract* is
   `[ systemPrompt, entityContext, conversationHistory ]`; both lanes
   converge on a `PromptContribution { weight: int, role: 'system'|'user'|'assistant', text }`
   shape so neither clobbers the other. **Lane D ships system-prompt
   contributions; this lane ships entity-context contributions.** Either lane
   can land first; the assembler tolerates zero contributions of the
   other kind.

8. **Budget v0: simple per-attachment modes.** Implemented as a
   `ContextBudgetPlanner` with two classes for v0:
   - `attached_entities` — admits full → summary → reference per ADR-016 (line
     191–210). Default budget: enough for ~one small document at full.
   - `transclusions` — **no-op placeholder** (admits zero tokens; class is
     present in the plan as the seam, per the transclusion guardrail).
   No async compaction. Token estimation: char-length / 4 heuristic for v0
   (good enough to prove the seam; refine later).

9. **`response.generate` keeps its current name and shape.**
   `ChatRespondLoop` already resolves `proxenos.task.chat` (F1) and is
   shape-compatible with `core.chat.respond` (ADR-014). Nothing renames here;
   the assembly swap is internal. `ChatRespondRequest` adds zero new fields —
   attachments are read from the projection by `threadId`. No write path
   beyond `core.document` create.

10. **`core.document` write path: one CRUD seam, one HTTP endpoint.** A
    minimal `POST /api/documents` (and `GET /api/documents/:id`) gated by the
    same auth as the SPA; create returns the instance, get returns
    `{ envelope, data }`. Bulk, search, delete, and projections beyond the
    instance row are out of scope. This is the slice's "real write" so the
    end-to-end loop is genuinely closed.

## Chunks

Each chunk is one focused commit with its own tests; `make test` + `make lint`
green at each step. The chunks are ordered so each one is independently
reviewable — there is no "everything in one PR".

1. **`core.document` type declaration (no Doctrine).** Pure PHP value objects
   in `src/TypedEntity/` plus a `CoreDocumentType` that emits the ADR-013
   envelope shape (schema + presentation hints + capabilities, with `kind:
   completion`-equivalent left implicit — this is a content type, not an
   operation). Unit tests assert JSON shape stability (snapshot test).
   **Contract out:** `TypedEntityDeclaration::envelope(): array` matches the
   §1 brief shape; `provider() === 'core'`, `type() === 'core.document'`.

2. **`CoreDocument` Doctrine entity + migration + repo + minimal CRUD.**
   Table `core_documents`. Repository `findOneByIdForTenant(uuid, tenantUuid)`.
   No HTTP yet. Unit test for the entity, integration test for the
   repository round-trip.
   **Contract out:** `CoreDocumentRepository::findOneByIdForTenant(): ?CoreDocument`.

3. **`Reference` envelope value object + `EntityResolver` service.** The
   `Reference` VO matches ADR-013a (§2 of the brief); `EntityResolver`
   dispatches on `provider` (only `core` for now) and `type` (only
   `core.document`) → returns `ResolvedReference`. Exercises both the
   resolved-instance path and the dangling path (id format ok but no row).
   Unit tests for: opaque-id byte-equality; dangling produces
   `instance === null, resolved === false`.
   **Contract out:** `EntityResolver::resolve(Reference, Uuid $tenantId): ResolvedReference`.

4. **Schema-driven renderer.** `EntityRenderer::render(envelope: array,
   instance: array, mode: 'card'|'pill'): RenderedEntity`. Pure function;
   honors `presentation.title` (JSON Pointer), `presentation.summary`
   (strategy object), `card_fields`/`detail_fields`. No HTML — pure DTO.
   Unit tests: card vs pill modes; missing optional presentation hints fall
   back to renderer defaults; JSON Pointer resolution.
   **Contract out:** `RenderedEntity { kind, title, summary, fields[], icon?, contentTypes[] }`.

5. **HTTP CRUD: `POST /api/documents`, `GET /api/documents/:id`.** Thin
   controller; same auth as the SPA. POST validates against `core.document`
   schema; GET returns `{ envelope, data }` so the SPA can render via the
   schema-driven path. Functional test for happy path + 404.

6. **Attach/pin: events, payloads, projection.** New event types
   `thread_entity_attached` / `thread_entity_detached`; new payload classes;
   new `ThreadAttachment` projection entity + migration; folds in
   `ProjectionFolder`. A small `ThreadAttachmentService` exposes
   `attach(threadId, Reference)`, `detach(threadId, provider, type, id)`,
   `listForThread(threadId): Reference[]`. Unit + functional tests for the
   fold (including the rebuild-idempotency path that every projection in
   this codebase already honors via `last_sequence`).
   **Contract out:** `ThreadAttachmentService::listForThread(): Reference[]`
   returns references in attach order.

7. **Entity-aware prompt assembly.** New `PromptAssembler` consumed by
   `ChatRespondLoop` in place of `assemblePromptFromProjection()`. Pipeline
   per decision 7. The assembler emits an ordered list of
   `PromptContribution`s and the loop folds them into the `MessageBag`
   (system contributions become a synthetic system message segment,
   conversation history follows). The budget planner is a small in-process
   service with the two classes from decision 8.
   **Contract out:** `PromptAssembler::assemble(threadId, tenantId): PromptContribution[]`.

8. **HTTP CRUD: attach/detach endpoints.** `POST /api/threads/:id/attachments`
   takes a `Reference` envelope (or the identity triple) and emits
   `thread_entity_attached`; `DELETE` emits the detached event. Functional
   test: attach a document, run a turn, observe that the assistant message
   includes the document's pill in its prompt (assert via a test-only inspect
   hook or by inserting a recording transport into the Platform).

9. **ADR-027b "core.document spine (Track A part 1)" ADR + escape-hatch
   evidence list.** Drop a new ADR file under `engineering/decisions/`
   recording the decisions above and opening the ADR-012 escape-hatch
   evidence list with the first entry (even if "renderer held").

## Test strategy

- **Unit tests** for each pure-ish component: type envelope shape;
  `Reference` value object; `EntityResolver` (resolved + dangling);
  `EntityRenderer` (card + pill, missing hints, JSON Pointer);
  `ContextBudgetPlanner` admission modes; `PromptAssembler` ordered
  contributions; expansion-policy downgrade with a debug log assertion.
- **Doctrine integration tests** for `CoreDocumentRepository`,
  `ThreadAttachmentService`, and the new `ProjectionFolder` folds (including
  the rebuild-idempotency `last_sequence` invariant the rest of the
  projections already enforce).
- **One functional test** end-to-end: create a document, attach it to a
  fresh thread, fire a chat turn against a **fake Platform** (already used
  by the existing chat tests), assert that the captured prompt MessageBag
  contains the document's pill-rendered system contribution **and** the user
  message, and that streaming + `assistant_turn_completed` still fire.
- **No live-model coverage required** for any of this. The Phase 0.5
  smoke command already exercises the real Anthropic / generic bridges.

## Definition of done

- [ ] `core.document` is declared (envelope = schema + presentation hints +
      capabilities), host-stored via Doctrine, resolvable by `Reference`
      envelope, and schema-rendered as a card/pill DTO.
- [ ] A `core.document` reference can be attached to a thread (event-sourced)
      and is serialized into the prompt **through the expansion-policy slot**;
      the `transclusions` budget class is present as a no-op.
- [ ] The turn runs through the existing `ChatRespondLoop` with entity-aware
      assembly; Phase 0.4/0.5 streaming + `assistant_turn_failed` behavior
      unchanged.
- [ ] `make test` + `make lint` green; the functional end-to-end attach test
      passes.
- [ ] First entry in the ADR-012 escape-hatch evidence list (in the new
      ADR-027b file).
- [ ] The new ADR-027b is committed to `engineering/decisions/`.
- [ ] The ordered-contributions `PromptContribution` shape is agreed with
      Lane D before any code that depends on it merges to main (capture in
      a short note in `current-handoffs/` once aligned).

## Open questions to resolve during execution

1. **Where does the type declaration live in code?**
   _Lean:_ `src/TypedEntity/Core/Document/CoreDocumentType.php` with a
   `TypedEntityDeclaration` interface so a second type later is additive.

2. **Does `core.document` carry a separate `title` column or derive it from
   the schema via JSON Pointer?**
   _Lean:_ Real column (`title varchar(200)`), because the SPA list view
   ordering by title is much cheaper as a column. Presentation hint
   `title: /title` still points at it.

3. **What is the `attached_entities` budget in v0, and what unit?**
   _Lean:_ Tokens, estimated as `floor(strlen / 4)`. Default budget: 4000
   tokens (one small doc fits at `full`, larger degrades to `summary`,
   very large to `reference`).

4. **System vs user message for the entity-context contribution?**
   _Lean:_ System. Anthropic and the OpenAI-compatible bridge both accept
   a system role, and that keeps the user turn clean for the model. The
   loop already builds a `MessageBag` of `ofUser`/`ofAssistant`; a
   `PlatformMessage::ofSystem` segment slots in cleanly.

5. **Coordinating with Lane D on the `PromptContribution` shape.**
   _Lean:_ Land this lane's contract first (it has more downstream
   constraints — budget, expansion slot) and ping Lane D for a
   thumbs-up before merging chunk 7. If Lane D pushes back, the change
   is local to `PromptAssembler` + `ChatRespondLoop`.

6. **Should `core.document` carry an `etag` / version column for the SPA
   PATCH path?**
   _Lean:_ Not in this slice. PATCH is an explicit non-goal here; POST +
   GET only. Add `version` when PATCH lands in part 2 or later.

7. **Where is the "first ADR-012 evidence list entry" recorded if the
   renderer holds?**
   _Lean:_ A heading in the new ADR-027b titled "ADR-012 escape-hatch
   evidence" with one bullet stating the slice's findings (even just
   "renderer held end-to-end on `core.document` — no escape hatch
   needed").
