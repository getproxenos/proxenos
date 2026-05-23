# Handoff: Schema Language — Note Walkthrough

## Context

This is a follow-up from a session on the schema language for entity types. That session produced working positions on the schema shape; this session's job is to verify those positions hold up against a concrete example — Hypomnema's `Note` type.

Relevant project files:
- `architecture-decisions.md` — ADR-003 (typed context entities), ADR-010 (host-mediated extension surface), ADR-012 (schema-driven rendering)
- `open-questions.md` — the "schema language for entity types" item under "Currently chewing on"
- `overview.md` — context on Hypomnema as the first concrete provider

## Working positions from the prior session

These are decisions made, subject to revision if the walkthrough surfaces problems.

**Schema language shape**
- JSON Schema for structural concerns; sibling presentation-hints object for display semantics; both wrapped in an envelope. Not `x-` keywords inside the schema. Not a custom DSL.
- `custom_renderer` field at envelope level for ADR-012's escape hatch.
- Hints are progressive — missing hints fall back to sensible defaults.
- Field references use JSON Pointers, not field names.
- Variant names (`"ok"`, `"warn"`) instead of raw colors in hints.

**Presentation-hint slots (provisional — this walkthrough should make the list authoritative)**
- `title`, `summary`, `icon`, `status`, `card_fields`, `detail_fields`, `references`, `external_link`
- `content_type` and `references` declared separately on a field — content type (markdown / plain text / code) and reference-bearing are orthogonal concerns.
- Icon names abstract; host maps to a concrete library (Lucide for web by default); extensions can namespace specific libraries (e.g., `octicons:repo`).

**Hypomnema's stance**
- Mirrors Obsidian's open-meaning approach. Exposes Vault, Note, Tag as primitives. Does not interpret frontmatter `type:` as creating typed subtypes.
- Wiki-link resolution is Hypomnema's job; rendering of resolved references is the host's. A sidecar list of resolved references travels alongside the body.

## Goal of this session

Produce a concrete schema-language envelope for Hypomnema's `Note` type. Specifically:

1. The full JSON Schema for Note's structural fields (title, body, frontmatter, backlinks, tags, created/modified dates, folder path).
2. The full presentation-hints object — every slot exercised at least once.
3. The envelope wrapping both, including routing fields (`custom_renderer`, version, type identifier, etc.).
4. Annotations explaining each choice.
5. A serialized instance of a Note alongside the type declaration, for comparison.

Questions the walkthrough should answer:
- Does the envelope shape hold up?
- Are the presentation-hint slots sufficient? Anything missing?
- How exactly is markdown-with-references expressed using the separated `content_type` + `references` hints?
- How are relationships (backlinks, tags) declared — structurally in the schema, in the hints, or both?
- What does the sidecar list of resolved references look like on an instance?

## Next session after this

Project + vault-slice walkthrough — stress-tests polymorphic membership and query-shaped references.

## Starting prompt

> Let's walk through a concrete schema-language envelope for Hypomnema's `Note` type. Start with the JSON Schema for structural fields, then the presentation hints, then the envelope wrapping both. Annotate each choice. Then show what an instance looks like serialized, including the sidecar of resolved references. Goal: make the slot vocabulary authoritative through use, and surface any gaps in the design so far.
