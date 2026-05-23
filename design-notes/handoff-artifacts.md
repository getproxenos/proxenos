# Handoff: Read/Write Artifacts Design

## Context

This is a follow-up from a session on the schema language for entity types, where the question of read/write artifacts emerged as a substantial side-thread. The motivating problem is closing the workflow gap between Claude.ai's read-only project files and Claude Code's "needs a repo" feel — allowing conversations to create and modify shared context documents inside the workspace itself. This is one of the explicit user pains the system aims to solve.

This isn't a direct continuation of the schema-language thread. It's a related design question that should be worked separately, ideally after ADR-013 (schema language) is in place — artifact capability declarations live in the schema envelope and need the envelope to exist first.

Relevant project files:
- `architecture-decisions.md` — ADR-003 (typed context entities), ADR-010 (host-mediated extension surface), ADR-013 (schema language, once it lands)
- `open-questions.md` — should grow an entry for "read/write artifacts" when this is flagged
- `overview.md` — Hypomnema glossary entry; Hypomnema going read/write is part of the motivating context
- `design-notes/note-walkthrough.md`, `design-notes/context-set-walkthrough.md` — the schema-language walkthroughs that produced ADR-013

## Motivating concerns

- Claude.ai-style project files (read-only from Claude's side) create the exact friction the new system is meant to solve. The "edit locally, delete, reupload" loop is the negative example.
- Hypomnema is currently read-only but will be read/write soon. Notes-as-artifacts is appealing because Notes are already first-class context entities with rendering, references, and the full schema-language treatment.
- Users without Hypomnema (or any extension) should still be able to save and edit things — a cold-start install shouldn't be capability-less.
- Designating a Vault and a path for "conversation-created Notes" raises its own UX and config questions even once Hypomnema is read/write.

## Three options considered in the prior discussion

1. **Core read/write artifacts.** Bake basic read/write artifact functionality into the core. Always-available baseline; but commits the core to storage concerns (versioning, backup, choice of underlying storage), and creates overlap with extension-provided typed storage like Hypomnema (two places Notes can live).

2. **Extensions advertise artifact capability for their entity types.** Most consistent with ADR-010's host-mediated primitive model. The core declares a "writable" capability; any provider can claim it for its entity types. Hypomnema advertises this for Notes once it goes read/write. Cold-start problem: a fresh install with no extensions has zero write capability.

3. **A dedicated artifacts extension with pluggable storage backends.** Functionally Option 1 with the storage logic moved into a first-party extension. The multi-backend storage abstraction (S3, NFS, document DBs) is real but not blocking — local filesystem plus Postgres blob covers the near-term, and switching later is fine if the artifact interface is right.

## Working position (subject to revision)

A hybrid of Options 1 and 2:

- **Artifact capability is a primitive in the extension surface.** A provider's entity-type declaration includes a `capabilities` field listing operations beyond `read` — `create`, `update`, `delete`, possibly `rename`. This folds into the schema-language envelope rather than being a separate concept. Capability is per-type per-provider, not global.
- **The core ships with one baseline artifact-capable entity type — `Document` — backed by host storage.** Guarantees that a zero-extensions install can still save and edit things. The core acts like its own extension for this one type.
- **Extensions advertise their types as artifact-capable when configured for it.** A Hypomnema instance pointed at a vault with a designated "conversation outputs" folder declares Notes as `create/update`-capable. The capability is a runtime property tied to the extension's config, not a static fact about the type.
- **Users (or per-conversation settings) pick the default artifact target.** Default-target with optional per-request override. If only the baseline `Document` type is artifact-capable, no picker is needed.

## Sub-questions for the dedicated session

- **Vault location for Hypomnema-as-artifacts.** Designated folder, tag-based filtering, per-conversation config? Probably a Hypomnema config concern, not a host concern, but the host needs to expose enough surface for users to choose at conversation-attach time.
- **Versioning and undo.** If a conversation modifies an existing artifact, what's the recovery story? Probably a short undo history at the artifact-operation level in the host, separate from whatever versioning the underlying storage does (git, filesystem, etc.).
- **Identity and namespace collisions.** When both core `Document` and Hypomnema `Note` are artifact-capable, picker UX has to disambiguate. Tractable but real.
- **Augmentation surface overlap with the Project primitive.** Augmentations might be the natural place to say "given this Project's attached vault-slice, route new artifacts here by default." Worth thinking through together rather than separately.
- **Exact `capabilities` field shape.** What operations are listed? Are there preconditions ("update requires existing identity")? How does it interact with `custom_renderer` and other envelope-level fields?
- **Operation semantics on the wire.** Does `create` require a target location, or is target negotiated separately? Is `delete` soft or hard? These decisions affect the JSON-RPC message types ADR-010 needs to firm up.

## Prerequisites

- ADR-013 (schema language) should be in place. Artifact capability declarations live in the envelope.
- The Context Set walkthrough should be done — there's likely overlap with context-set-scoped artifact defaults.
- Hypomnema's read/write story should be at least roughed in (even if not fully implemented) so the artifact-target case for Notes is concrete enough to reason about.

## Goal of the dedicated session

- Decide on the hybrid shape (or an alternative).
- Draft the exact `capabilities` field shape in the schema envelope.
- Specify what the baseline core `Document` type looks like and where it's stored.
- Sketch the picker UX for "where does this artifact go?"
- Identify what's core, what's extension-declared, what's user-config.
- Likely produces an ADR (call it ADR-014 as a placeholder until numbering settles).

## Starting prompt

> Let's design read/write artifacts for the workspace. ADR-013 (schema language) is now in place. The working position is a hybrid: artifact capability is declared per-entity-type via a `capabilities` field in the schema envelope; the core ships with a baseline `Document` type backed by host storage; extensions like Hypomnema advertise their types as artifact-capable when configured for read/write; users (or per-conversation settings) pick the default artifact target. Walk through this shape concretely — the schema-envelope addition for capabilities, the baseline `Document` type declaration, an example Hypomnema Note declaration with artifact capability enabled, and the picker semantics. Surface decisions worth making explicit: operation set, target selection, versioning/undo posture, and how the Project primitive's augmentation surface might default artifact targets.
