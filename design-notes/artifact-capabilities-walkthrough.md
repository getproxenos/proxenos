# Read/Write Artifacts — Capabilities Walkthrough (Result)

Worked output of the read/write artifacts handoff. The motivating problem is the
workflow gap between Claude.ai's read-only project files (edit locally, delete,
reupload) and Claude Code's "needs a repo" feel: a conversation should be able to
**create and modify shared context documents inside the workspace itself**, and have
what it creates become a first-class, citable entity in the same breath.

This takes the handoff's working position — a hybrid of "core read/write artifacts"
and "extensions advertise artifact capability" — and pressure-tests it against two
concrete types: the baseline host `core.document` and a write-enabled
`hypomnema.note`. Where a position survived contact it's kept; where it bent, the bend
is called out in §8.

Prerequisites in place: ADR-013 (schema language / type envelope), ADR-010 (host-mediated
extension surface), ADR-014 (Operation Registry), ADR-003 (typed entities / provider
registry). This document is the input to **ADR-017**.

> **Numbering note.** The handoff used "ADR-014" as a placeholder for this work. That
> number was since taken by the Operation Registry. This work lands as **ADR-017**.

---

## 1. Frame

Everything ADR-013 declares about an entity type is **read-facing**: `schema` says what
the type *is*, `presentation` says how to *show* it, the routing fields say how to
*dispatch* it. Nothing in the envelope says a conversation may *write* one. Closing the
workflow gap means adding a write surface — without turning the clean read model into a
storage engine, and without committing the core to one provider's storage choices.

The design splits into three layers, each owned by an existing ADR, with no overlap:

| Layer | Question it answers | Home |
|---|---|---|
| **Capability surface** | *Does this type support create/update/delete/rename, and what's the contract?* | ADR-013 envelope (this work adds it) |
| **Execution** | *How is a write actually run, accounted, and side-effect-classed?* | ADR-014 Operation Registry |
| **Wire + runtime availability** | *Which operations are live in this deployment, and how does the request travel?* | ADR-010 capability negotiation + JSON-RPC |

The load-bearing move is keeping these distinct. The *type* declares a stable,
versioned **capability surface** ("Notes can be created, here are the writable fields").
A *deployment* reports **runtime availability** at handshake ("this Hypomnema is pointed
at a read-only vault, so create is not live"). The handoff's worry — that artifact
capability is "a runtime property tied to config, not a static fact about the type" — is
resolved by this split, not by making the type declaration itself unstable. This is the
write-side analogue of the Note walkthrough's "schema says what exists; hints say where
it shows": **the envelope says what operations the type contractually supports;
negotiation says which are live and against what targets.**

Locked positions carried in from the handoff:

- **Capability is per-type, per-provider** — not a global host flag.
- **The core ships one baseline artifact-capable type, `core.document`** — so a
  zero-extension install can still save and edit.
- **Extensions advertise their own types as artifact-capable when configured for it.**
- **A default artifact target is resolved per-conversation, with per-request override.**

---

## 2. The envelope addition — `capabilities`

ADR-013's envelope carried `envelope_version`, `type`, `type_version`, `provider`,
`custom_renderer`, `schema`, `presentation`. This adds one sibling top-level field,
`capabilities`:

```json
{
  "envelope_version": "1",
  "type": "core.document",
  "type_version": "1.0.0",
  "provider": "core",
  "custom_renderer": null,
  "schema": { "...": "structure (§4)" },
  "presentation": { "...": "hints (§4)" },
  "capabilities": { "...": "the write contract (§3)" }
}
```

Three properties make this an **additive seam**, not a breaking change — the same
discipline ADR-012/013 prized:

- **Absence means read-only.** A type with no `capabilities` key is read-only — which is
  every type declared so far (`hypomnema.note` §4-old, `core.context_set`, the Iris
  memory types). They need no edit to stay valid. Read-only is the default, exactly as it
  is today.
- **Adding a top-level routing field bumps `envelope_version` to `"1"`.** This is the
  first exercise of the rule the Note walkthrough wrote down (§4: "bumped when the
  envelope grammar changes... the day a new top-level routing field is added"). The host
  interprets a `"0"` envelope as implicitly read-only, so old declarations keep working
  unchanged; a provider re-stamps to `"1"` only when it actually wants to claim a
  capability. `type_version` is untouched by this — it tracks the *dialect*, and a type's
  structure didn't change just because the grammar grew a slot.
- **`capabilities` is progressive, like presentation hints.** Each operation is present
  only if supported; a missing operation is simply not offered.

**Why `capabilities` rides with `type_version` and not, say, a separate runtime
message.** The *contract* (which fields are writable, whether delete is soft, whether
rename rewrites identity) is a stable fact about the type that the provider vouches for,
and changing it — making delete hard, or adding `/title` to the writable set — is a
type-version bump. It belongs next to `schema`. What is *not* in the envelope is
**availability**: whether create is switched on right now. That's reported separately at
ADR-010 negotiation time (§6). Contract is versioned and stable; availability is runtime
and per-deployment.

---

## 3. The `capabilities` shape

```json
"capabilities": {
  "create": {
    "writable_fields": ["/title", "/body", "/tags", "/collection"],
    "target": { "required": false },
    "identity": "host_generated"
  },
  "update": {
    "writable_fields": ["/title", "/body", "/tags", "/collection"],
    "preconditions": ["existing_identity"],
    "concurrency": "version_token"
  },
  "delete": { "mode": "soft", "reversible": true },
  "rename": { "supported": false }
}
```

(That's `core.document`'s contract; the Note's differs — §5.)

Operation by operation:

- **`create`** declares **`writable_fields`** — a list of JSON Pointers into the schema
  naming exactly the fields a conversation may supply. This reuses ADR-013's
  JSON-Pointer-not-field-name discipline wholesale. The point is that *not every schema
  field is user-writable*: a Note's `/backlinks`, `/references` (the resolved sidecar),
  `/vault`, `/created`, `/modified` are **provider-derived**, never things the
  conversation hands over. `writable_fields` is the allowlist the host validates the
  write payload against; anything outside it is rejected before dispatch. `create` also
  declares whether it needs a **`target`** (where does the new thing go) and how
  **identity** is assigned — `host_generated` (the host mints a UUID) or
  `derived_from_target` (the provider computes the id from target + payload, e.g. vault +
  folder + slugified title).
- **`update`** declares its own `writable_fields` (often a *subset* of create's — see the
  Note, where `/title` is creatable but not updatable), the precondition
  **`existing_identity`** (you can only update something that already exists, addressed by
  `id`), and a **`concurrency`** posture. `version_token` means update carries the
  instance's last-known version/etag and the host rejects a stale write — the minimum
  needed so two conversations editing the same Document don't silently clobber.
- **`delete`** declares **`mode`** (`soft` | `hard`) and **`reversible`**. Soft-vs-hard is
  the provider's semantic to define: for `core.document`, soft = a tombstone in host
  storage, trivially reversible; for a vault Note, soft could mean Obsidian's `.trash`
  folder. The host doesn't impose one; it surfaces what the type declares so the UI can
  warn appropriately ("this permanently deletes the file" vs "moves to trash").
- **`rename`** is **deliberately a separate operation from update**, because it mutates
  **identity**. For path-addressed types (Note, Document-with-collection), the `id`
  encodes the path, so renaming changes the `id` and breaks every reference pointing at
  the old one. That's a categorically different blast radius than editing a body, and it
  carries a distinct concern — **reference rewriting** — that update never does. A type
  declares `rename: { rewrites_identity: true }` to signal it; the host then knows the
  returned instance has a *new* id and that inbound references may need follow-up. (Who
  rewrites them is the provider's job — §5.)

**Why an operation map and not a flat `["create","update"]` list.** The handoff floated
a bare capability list. It doesn't survive the first concrete type: create and update
have *different* writable sets, delete has a mode, rename rewrites identity. The
operations aren't uniform flags; each carries its own contract. A map keyed by operation,
each value a small contract object, is the shape that holds. An absent key = unsupported,
preserving the progressive-disclosure feel of a list.

---

## 4. Baseline: the `core.document` type

The guarantee is that a **zero-extension install can still save and edit things**. The
core acts as its own provider for exactly one artifact-capable type, `core.document`.

**Schema (structure only):**

```json
{
  "$schema": "https://json-schema.org/draft/2020-12/schema",
  "type": "object",
  "additionalProperties": false,
  "properties": {
    "title":      { "type": "string" },
    "body":       { "type": "string" },
    "tags":       { "type": "array", "items": { "type": "string" } },
    "collection": { "type": "string" },
    "created":    { "type": "string", "format": "date-time" },
    "modified":   { "type": "string", "format": "date-time" }
  },
  "required": ["title", "body", "created", "modified"]
}
```

**Presentation hints** (mirrors the Note, minus vault machinery):

```json
{
  "title": "/title",
  "summary": { "strategy": "excerpt", "source": "/body", "max_chars": 200 },
  "icon": "file-text",
  "card_fields": ["/tags", "/modified"],
  "detail_fields": ["/collection", "/created", "/modified", "/tags"],
  "content_types": [ { "field": "/body", "type": "markdown" } ]
}
```

**Capabilities:** the §3 block — `create`/`update`/`delete` present, `rename` off for v0
(a Document is addressed by its host UUID, not a path, so there's no filename to rename;
`/title` is just a mutable field).

**Storage and identity.** A Document is **host-storage-backed** — a `documents` table in
the host's existing Postgres (ADR-004 already runs it; "local filesystem plus Postgres
blob covers the near-term"). Identity is a host-minted UUIDv7, so the canonical `id` is a
host URI: `core://documents/019dd7a1-…`. Because identity is `host_generated`, **create
returns the id** — the conversation can't know it in advance.

**A Document is workspace-durable, not thread-scoped.** It outlives the conversation that
created it and can be edited from other conversations. So its state is **not** a
projection of any one thread's event log (ADR-004 is per-thread). Instead the host keeps
a **per-artifact mutation history** — create/update/delete/rename recorded as an ordered
log keyed by artifact id — applying ADR-004's event-sourced *posture* without conflating
it with the thread log. That history is what makes versioning and undo (§7) fall out for
free for the baseline type.

---

## 5. Write-enabled `hypomnema.note`

Take the Note envelope from the original walkthrough (§2-4 there) unchanged, and add a
`capabilities` block. This is the whole point of the per-type/per-provider model: the
*type* didn't change, the provider just claims a write contract for it.

```json
"capabilities": {
  "create": {
    "writable_fields": ["/title", "/body", "/tags", "/frontmatter"],
    "target": { "required": true, "kind": "hypomnema.vault_folder" },
    "identity": "derived_from_target"
  },
  "update": {
    "writable_fields": ["/body", "/tags", "/frontmatter"],
    "preconditions": ["existing_identity"],
    "concurrency": "version_token"
  },
  "delete": { "mode": "soft", "reversible": true },
  "rename": { "rewrites_identity": true }
}
```

What each line demonstrates:

- **`create.writable_fields` is the allowlist; everything else is derived.** The
  conversation supplies `/title`, `/body` (raw markdown, `[[wiki-links]]` intact),
  `/tags`, `/frontmatter`. The provider derives the rest: `/vault` and `/vault_path` from
  the **target**, `/references` by *re-resolving* the body's wiki-links into the sidecar,
  `/backlinks` from the graph, `/created`/`/modified` from the filesystem,
  `/folder_path` for display. This is exactly the read-walkthrough's "resolution is
  Hypomnema's job, the result lands in `references` not `body`" — now seen from the write
  side: the conversation writes raw markers; the provider owns resolution.
- **`create.target` is required, kind `hypomnema.vault_folder`.** A Note has no identity
  without a vault (the read walkthrough's §0 correction). So create *must* be told which
  vault and folder — the "designated conversation-outputs folder." `identity` is
  `derived_from_target`: the provider composes `vault_path` (folder + slugified title +
  `.md`) and returns the canonical `hypomnema://…` id. Path collisions (a note already
  there) are the provider's call to resolve — error or de-dup suffix — and the **returned
  id is authoritative**.
- **`update.writable_fields` omits `/title`.** In Obsidian a note's title is its
  filename. Changing it moves the file → changes `vault_path` → changes `id`. That's not
  an update, it's a **rename**. This is the concrete reason rename is a separate operation:
  the same edit ("change the title") is an in-place field write for `core.document` (UUID
  identity, title is just data) but an identity-rewriting move for `hypomnema.note` (path
  identity). The capability map lets each type say which it is.
- **`rename.rewrites_identity`** surfaces reference integrity. Renaming a Note changes its
  id, so other notes' `[[links]]` and `backlinks` now point at a stale id. For Hypomnema
  this is *already solved by the provider*: Obsidian rewrites wiki-links on rename. So the
  host's job is only to **learn the new id** and re-emit the instance; the provider owns
  the cascade. The host's contribution is the operation-level undo record (§7), not the
  link rewriting.

**Runtime availability is where config bites.** This envelope *declares* all four
operations. But a Hypomnema instance pointed at a **read-only vault**, or one with **no
designated outputs folder configured**, reports at ADR-010 handshake that create/update
are **not live**. The host then shows Note as read-only — no save affordance, no picker
entry — even though the type's contract supports writing. A user who later configures a
writable vault + outputs folder flips availability on without any change to the type
declaration. *That* is the handoff's "runtime property tied to config," landed cleanly in
the negotiation layer.

---

## 6. Execution, the wire, and closing the loop

A declared capability is not yet a runnable thing. The execution layer is **ADR-014**, and
the binding is: **declaring a capability on a type is the registration of its write
operation.** The provider does not separately hand-register `hypomnema.note.create` in the
Operation Registry — the host *synthesizes* the operation contract from the capability:

- **operation id** = `<provider>.<type-tail>.<op>`, e.g. `hypomnema.note.create`,
  `core.document.update`.
- **input schema** = derived from `writable_fields` (projected out of the type's JSON
  Schema) + the `target` declaration.
- **side-effect class** = `external_write` (ADR-014 already carries side-effect
  declaration) — these are the operations that *change the world*, so they're the ones
  policy, accounting, and confirmation gating hang off.
- **executor** = the type's provider: `host` for `core.document`, the Hypomnema service
  over ADR-010 JSON-RPC for `hypomnema.note`.

The end-to-end path:

1. The host exposes a write affordance to the model as a tool (e.g. `save_artifact`)
   whose input schema is the chosen type's create/update contract. The tool is only
   present when at least one create-capable target is **live** (§5).
2. The model (or the user via a Save button) invokes it: payload + chosen target.
3. The host **validates** — writable fields only, required target present, preconditions
   (`existing_identity` for update) met, concurrency token fresh — *before* any dispatch.
4. The host dispatches to the executor (host storage, or provider JSON-RPC).
5. The executor performs the write and returns the **resulting lean instance**
   (`{ type, id, data }`) — with the host-assigned or provider-derived id, and all
   derived fields (resolved `references`, `backlinks`, timestamps) populated.
6. The host emits that instance as a **citable entity event into the thread** (ADR-004).

Step 6 is the payoff that closes the workflow gap. **The thing a conversation just
created is immediately a first-class entity** — same envelope, same references, same
rendering as anything read in — so it can be cited, pinned, attached, and linked *in the
same conversation that made it*. Write produces exactly the kind of object read consumes;
there is no second-class "artifact" representation. That's the property Claude.ai's
read-only project files can't offer.

---

## 7. Versioning & undo posture

Two layers, deliberately separate (the handoff's instinct, confirmed):

- **Provider/storage versioning** is the provider's own. Hypomnema's underlying vault may
  be under git, Obsidian's file history, or nothing; `core.document` uses its per-artifact
  mutation log (§4). The host does not impose a versioning model on providers.
- **Operation-level undo** is the host's, and it's thin: the host records *the write this
  conversation just issued* (the before-image or the inverse operation) so a user can
  **undo a conversation-initiated change** — even against a provider whose own history is
  opaque. Undo of a create = delete the created instance; undo of an update = re-issue the
  prior payload; undo of a soft delete = restore. This is operation-level, not a general
  version browser, and it lives next to the thread so "undo what the assistant just wrote"
  is one action.

For `core.document` the two layers coincide (the per-artifact mutation log *is* both the
version history and the undo source). For provider types they're distinct: host undo
reverses the host-issued operation; deeper history is the provider's.

---

## 8. Target selection & the picker

When a conversation says "save this," **where does it go?** The candidate set is every
**(type, target)** pair whose create capability is *live* in this deployment.

- **Zero or one candidate → no picker.** A bare install has only `core.document` → it
  just saves there. (The handoff's line 37: "if only the baseline `Document` type is
  artifact-capable, no picker is needed.")
- **Two or more → a resolved default with override.** The default artifact target is
  resolved by precedence:

  1. **Per-request override** — the user/model named a target this time.
  2. **Per-conversation sticky** — the target chosen earlier in this conversation.
  3. **Context Set augmentation default** — see below.
  4. **User default** — a profile setting.
  5. **Baseline fallback** — `core.document`.

**The Context Set (Project primitive) angle.** This is where the handoff's
"augmentation surface should default artifact targets" lands concretely. A Context Set
attached to the conversation can carry an **artifact-target augmentation**: "new artifacts
from conversations grounded in this set go to Hypomnema vault X, folder /Decisions." A
conversation about a project then defaults its saves to that project's vault slice without
the user re-choosing each time. This makes the augmentation surface
(`extension-augmentation-notes.md`) grow a new augmentation *kind* —
**artifact-target** — alongside actions/summaries/suggestions/lifecycle-hooks. The
augmentation supplies a default create-target for the primitive it's attached to; it does
not bypass the capability model (the target it names must still be a live create
capability).

**Picker UX when ambiguous.** A small target picker lists the live create-capable
(type, target) options, **disambiguated by a human location label, not the raw id** —
this is the handoff's "identity & namespace collisions" concern, and the answer is the
same disambiguation a person would use:

```
Save as…
  ● Note      · personal vault · /Decisions      (Hypomnema)
  ○ Note      · work vault · /Inbox              (Hypomnema)
  ○ Document  · workspace                        (core)
```

Default selection = the resolved default. Choosing here sets the per-conversation sticky
(precedence rung 2), so subsequent saves in the thread don't re-prompt.

---

## 9. What's core / extension / user-config

The handoff's final ask — draw the three boundaries:

**Core (host) owns:**
- the `capabilities` envelope field + its grammar (and the `envelope_version` bump);
- the baseline `core.document` type, its Postgres-backed storage, and its per-artifact
  mutation log;
- the write-execution path: tool exposure, payload validation against `writable_fields` +
  target + preconditions + concurrency, dispatch, and emission of the result as a citable
  entity event (§6);
- the operation-level undo log (§7);
- target resolution + the picker (§8);
- synthesizing the ADR-014 write operation from a declared capability;
- the capability-negotiation surface that collects runtime availability.

**Extension-declared:**
- which of its types are artifact-capable, and each operation's contract
  (`writable_fields`, `target.kind`, `delete.mode`, `rename.rewrites_identity`);
- the actual storage/write implementation behind the executor;
- **runtime availability**, reported at handshake from its own config (read-only vs.
  writable vault; outputs folder configured or not);
- derived-field computation on write (reference re-resolution, timestamps) and reference
  rewriting on rename.

**User / per-conversation config:**
- the default artifact target and the inputs to its precedence (user default,
  per-conversation sticky, per-request override);
- provider-side target config (e.g. *which* Hypomnema vault + folder is the
  conversation-outputs target) — a Hypomnema config concern the host only needs to
  *surface* at conversation-attach time, not own;
- (via a Context Set) the set-scoped artifact-target augmentation.

---

## 10. Decisions & gaps

### Decisions locked this session

- **Three non-overlapping layers** (§1): ADR-013 declares the capability surface, ADR-014
  executes, ADR-010 carries the wire and reports runtime availability.
- **`capabilities` is a new sibling top-level envelope field**; **absence = read-only**;
  **adding it bumps `envelope_version` to `"1"`** (first use of that rule), additively —
  `"0"` envelopes are read as read-only.
- **An operation *map*, not a flag list** — create/update/delete/rename each carry a
  distinct contract (§3).
- **`writable_fields` as JSON Pointers** — the read walkthrough's field-reference
  discipline, applied to writes; non-writable fields are provider-derived.
- **`rename` is separate from `update`** because it rewrites identity and carries
  reference-integrity consequences update never does.
- **Capability surface (versioned, stable) vs. runtime availability (per-deployment,
  negotiated)** — the resolution of "capability is tied to config."
- **The baseline `core.document`** is host-Postgres-backed, UUIDv7 identity, with a
  per-artifact mutation log giving versioning/undo for free.
- **Write emits a citable entity into the thread** (§6) — the property that actually
  closes the workflow gap.
- **Default-target precedence with per-conversation stickiness**, and a new
  **artifact-target augmentation kind** for Context Set-scoped defaults (§8).

### Gaps to carry forward

- **Concurrency model depth.** `version_token` optimistic concurrency is declared but the
  exact token (etag? mutation-log sequence? `modified` timestamp?) and the conflict-UX
  ("someone else edited this; keep yours / theirs / merge") are unspecified. Fine for a
  single-user v0 (ADR-006); needs real shape before multi-user editing.
- **Bulk / multi-file artifacts.** A conversation that wants to write *several* linked
  notes (or a folder) is out of scope here — every operation in §3 is single-instance.
  Whether a batch/transaction capability is needed is deferred.
- **Confirmation & autonomy policy.** `external_write` operations are the ones that should
  arguably require confirmation before the model runs them unattended. Where that gate
  lives (host policy on the side-effect class? per-operation? per-target?) is an ADR-014
  side-effect-policy question this work surfaces but doesn't settle.
- **The artifact-target augmentation's exact registration shape** rides on the still-open
  augmentation-surface design (`extension-augmentation-notes.md` §2) — this work names the
  *kind* and its job, not the wire registration.
- **`core://` URI canonical form.** Provisional, the same way `hypomnema://` is
  (read walkthrough §6) — authority, tenant-scoping, and collection-path encoding need
  settling alongside the other host-native ids (Context Set's id form is equally
  unsettled).
- **Rename reference-rewriting for providers that *don't* self-heal.** Hypomnema/Obsidian
  rewrites wiki-links on rename; a provider that doesn't leaves dangling references after a
  host-issued rename. Whether the host offers a reference-rewrite assist, or just reports
  `rewrites_identity` and lets references dangle (read walkthrough already models dangling
  references), is open.
- **Cross-type "save as".** The picker (§8) lets a user save the same content as either a
  Note or a Document. Whether those are independent artifacts or one logical thing with two
  representations is the **cross-provider type overlay** question
  (`extension-augmentation-notes.md` §3), now reachable from the write side too.
