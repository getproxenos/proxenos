> **Relocate:** move to `engineering/decisions/` once the engineering/ scaffolding lands.

# ADR-027b — core.document spine (Track A part 1)

**Status:** Accepted · 2026-06-16
**Parent:** ADR-027 (model-profile / task-intent taxonomy) — this is a Track A
sub-decision recording the first vertical implementation slice.
**Related:** ADR-012, ADR-013, ADR-013a, ADR-014, ADR-016, ADR-017, ADR-018.

## Context

Step-02 cuts the first vertical slice through the typed-context layer against a
**host-native `core.document`** entity, end to end:

```
declare → store → resolve → render → attach → assemble → budget → loop → stream
```

The slice proves the product thesis — typed entity → schema render → reference
resolution → attach → entity-aware prompt assembly → budget admission → the
existing turn loop → stream — **without opening the ADR-010 process boundary**.
Hypomnema and the first *external* JSON-RPC provider are deliberately deferred to
part 2 so the spine is not held hostage to the separate Hypomnema project.

The slice exercises (and validates in code, not just on paper) the seams named by
the related ADRs:

- **ADR-012** schema-driven rendering (card/pill) and its deferred code escape hatch.
- **ADR-013 / ADR-013a** the type-declaration envelope and the universal reference
  envelope (`{ provider, type, id }` + `resolved_reference` sidecar).
- **ADR-014** the operation *shape* (`core.chat.respond`) — reused, **not** the registry.
- **ADR-016** budget admission modes (`full → summary → reference`) and the
  transclusion budget class kept as a no-op seam.
- **ADR-017** the host-storage baseline for `core.document`.
- **ADR-018** prompt declaration v0 (entity-aware assembly replacing dumb concat).

## Decision

The ten step-02 decisions, as built (commits noted per chunk):

1. **`core.document` is the only typed entity in this slice.** No second type,
   no second provider, no `core.memory`. The point is to prove the
   resolve/render/attach/serialize seams once, end to end.

2. **Type declaration is a host-side, in-process value object.** A
   `TypedEntityDeclaration` (`CoreDocumentType`) emits the ADR-013 envelope
   (`type` / `type_version` / `provider` / `schema` / `presentation` /
   `capabilities`) as plain PHP data. **No registry** — ADR-014 is F2; the
   renderer and resolver dispatch on `provider + type` from the envelope object,
   not a registry. The shape is registry-adoptable later. (chunk 1)

3. **Instances live in a Doctrine entity (`CoreDocument`); documents are CRUD,
   not event-sourced.** Host-stored per the ADR-017 baseline (`id` uuid v7,
   `tenantId`, `title`, `body`, `tags` jsonb, `collection`, timestamps,
   `createdByUserId`). The document body is **not** a fold of events — only the
   conversation log has to be replayable; attach/pin is the event-sourced part
   (decision 6). (chunk 2)

4. **Reference envelope is a value object (`Reference`) per ADR-013a.** Fields:
   `provider`, `type`, `id`, `resolved`, optional `marker` / `label` /
   `expansion` (`pill | summary | full`, default `pill`) / `snapshot` / `target`.
   `id` is opaque — compared by byte-string. The resolver returns
   `ResolvedReference { reference, instance|null, sidecar }`; `instance === null`
   means dangling. (chunk 3)

5. **Schema-driven renderer is a pure function producing a DTO — no custom
   renderer.** `EntityRenderer::render(envelope, instance, mode)` →
   `RenderedEntity { kind: 'card'|'pill', title, summary?, fields, icon?,
   contentTypes }`. No HTML, no provider-specific code; honors
   `presentation.title` (JSON Pointer), `presentation.summary`, and
   `card_fields` / `detail_fields`. **No `custom_renderer` escape hatch** in this
   slice — start the ADR-012 evidence list instead (see below). Track D consumes
   the DTO via the leaf-renderer seam. (chunk 4)

6. **Attach/pin is event-sourced (2 events + a projection).** Two new
   conversation events on the existing log — `thread_entity_attached`
   (`{ reference, attachedAt? }`) and `thread_entity_detached`
   (`{ provider, type, id }`, byte-equality on the identity triple) — folded into
   a new `thread_attachments` projection (composite key
   `thread_id + provider + type + id`, plus `attached_at`, `last_sequence`).
   Event-log storage is varchar, so no migration on the log itself. (chunk 6)

7. **Entity-aware prompt assembly via `PromptAssembler`.** A small orchestrator
   wired into `ChatRespondLoop` in place of the dumb projection-only concat:
   `thread → attachments projection → resolver → expansion-policy slot → budget
   admission → serialized fragment → MessageBag`. The expansion slot honors
   `pill`; `summary` / `full` are silently downgraded with a debug log. The
   fragment is prepended as a **system** segment ahead of conversation history.
   Cross-cut with Lane D via the ordered-contributions contract
   `[ systemPrompt, entityContext, conversationHistory ]`: both lanes emit
   `PromptContribution { weight, role, text }`; the loop sorts by `weight`
   ascending and folds. Lane D ships system-prompt contributions (`weight < 100`);
   this lane ships entity-context contributions (`weight = 100`). Either lane can
   land first; the assembler tolerates zero contributions of the other kind. (chunk 7)

8. **Budget v0: simple per-attachment modes (`ContextBudgetPlanner`).** Two
   classes: `attached_entities` admits `full → summary → reference` per ADR-016
   (default budget ≈ one small document at full); `transclusions` is a **no-op
   placeholder** (admits zero tokens — the seam is present, not deleted, per the
   transclusion guardrail). No async compaction. Token estimate: `floor(strlen / 4)`
   heuristic for v0. (chunk 7)

9. **`response.generate` keeps its current name and shape.** `ChatRespondLoop`
   already resolves `proxenos.task.chat` (F1) and is shape-compatible with
   `core.chat.respond` (ADR-014). Nothing renames; the assembly swap is internal.
   `ChatRespondRequest` adds **zero** new fields — attachments are read from the
   projection by `threadId`. No write path beyond `core.document` create.

10. **One CRUD HTTP seam.** `POST /api/documents` (create, returns the instance)
    and `GET /api/documents/:id` (returns `{ envelope, data }`), gated by the same
    auth as the SPA; plus the attach/detach thread endpoints
    (`POST` / `DELETE /api/threads/:id/attachments`). Bulk, search, delete, and
    projections beyond the instance row are out of scope. This is the slice's
    "real write" that genuinely closes the end-to-end loop. (chunks 5, 8)

**Built across chunks 1–8** on `step-02/core-document-spine`: chunks 1–4 (type
declaration, Doctrine entity + repo, `Reference` + `EntityResolver`, schema-driven
renderer) and chunks 5–8 — HTTP CRUD (`85525ca`), attach/pin events + projection
(`5b96388`), entity-aware prompt assembly (`511039f`), and attach/detach endpoints
plus the end-to-end entity-aware turn test (`3db2cdc`). `make test` + `make lint`
green at each step; the functional attach test fires a turn against the fake
Platform and asserts the document's pill lands in the prompt MessageBag.

## ADR-012 escape-hatch evidence

ADR-012 ships the schema-driven renderer first and adds the code escape hatch
(`custom_renderer`) only when a real type pinches the generic path. This is the
running evidence list of where the generic renderer holds or pinches — **future
slices append one bullet each**:

- **2026-06-16 · `core.document` (step-02, this slice): renderer held
  end-to-end.** The pure schema-driven renderer produced card/pill `RenderedEntity`
  DTOs across resolve → render → attach → assemble with **no `custom_renderer`
  escape hatch needed**. JSON-Pointer title resolution and the summary/field hints
  covered every presentation case the slice exercised. No pinch surfaced.

## Consequences / deferred

- **Part 2 — Hypomnema + the ADR-010 process boundary** is deferred. Opening
  ADR-010 and adding the first *external* JSON-RPC provider is the real
  extensibility-without-forking test; the same resolve/render/attach/serialize
  seams will then cross a process boundary.
- **Transclusion seam stays open but only `pill` is honored.** Serialization
  routes through the expansion-policy slot (never hardcodes "references are
  pills"), and the `transclusions` budget class remains a no-op placeholder.
  Reviving transclusion is therefore an additive ADR, not a wire-format migration.
- **Operation registry (ADR-014) is F2.** This slice reuses `ChatRespondLoop`'s
  registry-adoptable call/return shape; it does not build the registry.
- **Lane D (SPA) rendering** plugs into the leaf-renderer seam (the `RenderedEntity`
  DTO) from chunk 4; SPA card/pill rendering is downstream.
- **The `PromptContribution` shape needs Lane D's thumbs-up before merge to
  `main`.** It is shipped by this (entity-context) lane but the ordered-contributions
  contract must be agreed with Lane D — if Lane D pushes back the change is local to
  `PromptAssembler` + `ChatRespondLoop`. See
  `current-handoffs/handoff-prompt-contribution-contract.md`.
