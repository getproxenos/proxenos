# Extension Augmentation Surface — Design-in-Progress Notes

> **Tracked in Linear:** [BDS-39 — Extension augmentation pipeline](https://linear.app/beausimensen/issue/BDS-39) · epic [BDS-35 — external-provider boundary](https://linear.app/beausimensen/issue/BDS-35).

In-progress design scratchpad, not an ADR. The hook surface by which extensions augment
host primitives without being part of a primitive's schema.

**Source open questions:** "Augmentation surface for Context Sets" and "Cross-provider type
overlay" in `open-questions.md` (Architecture-shaped).
**Anchored by:** ADR-010 (host-mediated extension surface, pipeline-hook subcategory),
ADR-003 (provider registry), ADR-013 (entity type declarations, universal references).

---

## 1. Frame

ADR-013 deliberately keeps provider-specific **actions, summaries, suggestions, and lifecycle
hooks** *out* of a primitive's schema. The `core.context_set` schema describes structure and
display; it says nothing about "summarize this set," "suggest a member," or "add an Open in
Foo action." Those are augmentations, and they need a registration surface.

This is the entity-augmentation slice of ADR-010's broader extension model. The likely shape is
a **pipeline-hook subcategory**: extensions declare intent ("I provide a summary for type X",
"I propose suggestions against a Context Set"), and the host decides how/whether that intent
surfaces — the same host-mediated principle as everywhere else.

---

## 2. Open threads

- **Registration shape.** How does an extension declare an augmentation against a type? A
  capability advertised at handshake (ADR-010 capability negotiation), keyed by
  `provider.type`? A per-primitive hook manifest? Reuse the "14 operations" pipeline-hook
  mechanism (`open-questions.md`: "Where does the '14 operations' pattern live?").
- **Augmentation kinds.** At least: **actions** (a button/command against an instance),
  **summaries** (provider-computed text/structured summary, distinct from the schema's
  `summary` slot), **suggestions** (proposals to attach/relate), **lifecycle hooks**
  (on-attach, on-resolve, on-serialize), **memory lifecycle hooks** surfaced by the
  Iris memory walkthrough: recall ranking, prompt serialization, memory->truth promotion,
  truth crystallization, conflict detection/resolution, and consolidation, and
  **artifact-target** defaults (named by ADR-017): a primitive — typically a Context Set
  attached to the conversation — supplying the default create-target for
  conversation-written artifacts ("new artifacts from conversations grounded in this set
  go to Hypomnema vault X, folder /Decisions"). It supplies a default; it does not bypass
  the capability model — the named target must still be a live create capability. Each may
  need a different host surface.
- **Targeting.** Augmentations against a *type* (all Notes), a *provider*, a *primitive*
  (Context Set as a whole), or a specific *instance*. Probably type- and primitive-level in v0.
- **Trust + ordering.** Multiple extensions augmenting the same type — who wins, what order do
  actions render, how does trust tiering (ADR-010 open question) gate which augmentations are
  allowed? Defer enforcement, design so it can be added.

## 3. Cross-provider type overlay (related)

ADR-013 makes references universal, which raises a question that lives partly here: **can two
providers reference the same underlying thing?** An exported Claude conversation is a Note via
Hypomnema *and* could be a Conversation via a dedicated provider — same bytes, two types.

- When the same underlying thing surfaces under two types, **which type's schema/hints win**
  for rendering and citation?
- Is overlay an *augmentation* (a Conversation provider augments a Note with conversation
  affordances) or a *distinct entity* with its own identity that happens to share a source?
- This connects to "conversation-as-entity" (`open-questions.md`) seen from the reference layer.

## 4. Design-with-in-mind

The **artifact-default work** that this surface was told to anticipate has landed: ADR-017
(read/write artifacts — see `design-notes/artifact-capabilities-walkthrough.md`) defines an
**artifact-target** augmentation kind (added to §2). The registration shape settled here must
therefore be able to carry a per-primitive default create-target, not just actions/summaries/
suggestions — so the hook shape doesn't have to be reworked now that artifacts have landed.
