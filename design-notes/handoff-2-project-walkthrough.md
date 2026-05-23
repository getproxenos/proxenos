# Handoff: Schema Language — Project + Vault-Slice Walkthrough

## Context

Second of three follow-ups from the schema-language session. The first walkthrough (Note) verified the basic envelope shape against a single-provider entity type. This session stress-tests the schema against the harder cases: polymorphic membership across providers, and query-shaped references.

**Prerequisite:** the Note walkthrough should be complete before starting this one. The Note envelope is referenced throughout.

Relevant project files:
- `architecture-decisions.md` — ADR-003, ADR-010, ADR-012
- `open-questions.md` — the "currently chewing on" items, plus the cross-cutting curation layer concept
- `overview.md` — context on the cross-cutting Project layer

Reference the completed Note walkthrough document (or paste the resulting envelope as context).

## Working positions from the prior session

**Project layer**
- The primitive (a named collection of typed references across providers) is host-native.
- Augmentations (provider-specific actions, summaries, suggestions, lifecycle hooks) are extension-shaped, not part of the primitive.
- Polymorphic membership across providers — members can be Hypomnema Notes, GitHub Repos, Linear Projects, Conversations, etc.
- Membership references come in two kinds: concrete entity references, and query-shaped references that resolve at attachment time (and re-resolve on later viewing).
- Name: TBD. "Project" overloads Claude.ai's existing concept; "Workspace" is taken in many other tools; "ContextSet" is precise but awkward. Pick before formalizing.

**Design questions for this session to resolve or sharpen**
- Universal entity-reference envelope with type constraints layered on, vs. distinct envelopes per reference kind?
- How are query-shaped references structured — a distinct reference kind with its own envelope, or a `Query` object that owns a `resolves_to` field?
- How does the schema express polymorphic membership in JSON Schema terms? (`oneOf` over typed references? An opaque `{provider, type, id}` triple as the universal member shape? A discriminated union?)
- How does the renderer display a heterogeneous member list? (Grouped by type? Chronological? User-ordered?)

## Goal of this session

Produce two concrete envelopes building on the Note walkthrough:

1. **The Project (or whatever you name it)** entity type envelope, including its polymorphic membership field, displayed against the Note envelope from session 1 as a known member type.
2. **A Hypomnema vault-slice** — `tag = #project-foo` or `folder = /Projects/Foo/` — as a query-shaped reference, showing both how it serializes and how it differs from a concrete Note reference.

Resolve along the way:
- The naming question (Project / Workspace / ContextSet / something else).
- Universal vs. per-kind entity-reference envelope. Pick one with rationale.
- Query-reference shape — distinct kind, or `resolves_to` field on a generic Query entity.
- How the renderer treats heterogeneous member lists.

Show:
- The Project type envelope (schema + hints + routing).
- A serialized Project instance with three members: a Note (concrete reference), a vault-slice (query reference), and a fictional GitHub Repo (to exercise polymorphism).
- The vault-slice's standalone serialization, to show what providers return when a query reference is resolved.

## Next session after this

ADR-013 (schema language formalized) + open-questions update + glossary additions.

## Starting prompt

> Building on the Note walkthrough, let's draft envelopes for two things: the Project entity type with polymorphic membership across providers, and a Hypomnema vault-slice as a query-shaped reference. Pick a name for the Project primitive — Project, Workspace, ContextSet, or something else, with rationale. Resolve the universal-vs-per-kind reference envelope question. Show what an instance of each looks like serialized, including a Project with three heterogeneous members.
