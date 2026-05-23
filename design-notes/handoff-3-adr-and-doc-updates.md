# Handoff: Schema Language — ADR-013 and Document Updates

## Context

Third and final follow-up. With the Note and Context Set walkthroughs complete, the schema language is ready to be formalized as an ADR and the project documents updated.

**Prerequisites:** both prior walkthroughs (Note + Context Set/vault-slice) should be complete. Their resulting envelopes are inputs to this session.

Relevant project files:
- `architecture-decisions.md` — add ADR-013 here
- `open-questions.md` — revise based on what's resolved vs. newly surfaced
- `overview.md` — glossary additions

Have the two completed walkthroughs available as context.

## Goal of this session

Produce text-ready updates for all three project documents, plus a possible new document for design-in-progress notes.

### 1. ADR-013: Schema language for entity types

Match the format of existing ADRs — Decision, Rationale, Ruled out, Open questions surfaced. The ADR should capture:
- JSON Schema + sibling presentation-hints object + envelope shape, grounded in the walkthroughs.
- The authoritative slot list, now that it's been exercised.
- The `custom_renderer` escape hatch routing.
- The reference-envelope decision (universal vs. per-kind, with whatever rationale the Project walkthrough produced).
- Query-reference treatment.
- Icon-namespace abstraction with host-provided mapping (Lucide as web default; namespaced extension form for domain-specific libraries).
- Cross-references to ADR-003, ADR-010, ADR-012.

### 2. open-questions.md revisions

**Resolve or remove:**
- "What is the schema language for entity types?" — answered, points at ADR-013.
- Whether user-defined typed notes (via Hypomnema) are first-class — Hypomnema doesn't do this; the question collapses.

**Add or sharpen:**
- **Transclusion design.** Depth limits, expansion shape (pill / summary / full body), cycle detection, prompt-budget integration. Touches wire protocol and response pipeline. Worth its own ADR eventually.
- **Cross-provider type overlay.** Exported conversations as Notes via Hypomnema vs. Conversations via a separate provider. The "can two providers reference the same underlying thing" question.
- **Project primitive naming** (if the walkthrough didn't fully settle it).
- **Augmentation surface for Projects.** How extensions register actions / summaries / suggestions against the Project primitive — likely a pipeline-hook subcategory.

**Existing items that should be re-touched:**
- "Attached entity serialization into the prompt" — now interacts with transclusion design.
- The hybrid injection / tool-call retrieval question — same.

### 3. overview.md / glossary additions

- Add a sentence to the Hypomnema glossary entry capturing its open-meaning stance ("Mirrors Obsidian's open-meaning approach: exposes vault primitives without imposing typed interpretations on content").
- Add a glossary entry for the Project primitive (or whatever it's named), including the distinction between concrete and query-shaped membership references.
- Consider a glossary entry for the schema-language envelope itself, since it's referenced repeatedly across docs.

### 4. Possible new document

The prior session raised a gap: the three existing docs cover stable identity (overview), settled decisions (ADRs), and unresolved scratchpad (open-questions). Missing: a place for design work *in progress* — too thick for a one-line open question, not stable enough for an ADR.

Claude.ai Projects are flat (no sub-directories), so the workable shape is single files with topic-suffixed names. Candidates:
- `transclusion-notes.md` (most urgent — it's the largest deferred design piece).
- `project-primitive-notes.md` (if the walkthrough left meaningful threads unresolved).
- `extension-augmentation-notes.md` (the hook surface for Projects).

Decide whether to start one or more now, or defer until pain compels it.

## Starting prompt

> With the Note and Project walkthroughs complete (referenced as context), let's formalize. First, draft ADR-013 (schema language) in the exact format of existing ADRs. Second, propose specific edits to `open-questions.md` — resolutions to remove, sharpenings, and new items. Third, draft glossary additions for `overview.md`. Finally, decide whether any in-progress design notes documents should be started now (transclusion is the leading candidate).
