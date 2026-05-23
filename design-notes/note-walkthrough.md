# Schema Language — Note Walkthrough (Result)

Worked output of the first Note walkthrough handoff prompt. This takes the working
positions from the prior schema-language session and pressure-tests them against Hypomnema's `Note` type:
a full envelope (JSON Schema + presentation hints + routing), a serialized instance with
its resolved-reference sidecar, and a closing pass that answers the handoff's five
questions and records every gap surfaced. Where a position survived contact with the
concrete type it's kept; where it bent, the bend is called out in §6.

This document is an input to `context-set-walkthrough` and the ADR-013 / document-update work.
The reference triple (§2) and lean-instance shape (§5) are written so those sessions can
pick them up directly.

---

## 0. Prerequisite — the Vault primitive

A Note doesn't stand alone: every file belongs to a **Vault**, and a file's path is only
unique *within* a vault. So before Note's identity makes sense, three things about Vault
have to hold. (The full Vault type envelope — its own schema + hints + routing — is
deferred to its own pass; this section establishes only what Note's identity depends on.)

- **Vault is discrete.** A Hypomnema instance connects to zero or more vaults; every Note
  is attached to exactly one. Vault is a first-class primitive alongside Note and Tag
  (ADR-003), not an attribute of a Note.
- **Vault identity is a UUID** — e.g. `019dd737-8435-7db3-937a-0884d6b0ce63` (a UUIDv7,
  time-ordered). The vault **name** (`personal`) is *not* identity: it's rename-able and
  unstable, useful only for debugging.
- **Entity URIs are vault-scoped.** The canonical identifier for any Hypomnema entity is a
  `hypomnema://` URI:

```
hypomnema://localhost/vaults/019dd737-8435-7db3-937a-0884d6b0ce63/Decisions/streaming-protocol.md
└─scheme─┘ └─ host ─┘        └──────────── vault UUID ───────────┘ └──── vault-relative path ────┘
```

  - **Canonical form carries an explicit authority** (`localhost`, or a remote host for
    network-connected Hypomnema). The empty-authority shorthand
    `hypomnema:///vaults/<uuid>/<path>` is accepted on input and normalized to the explicit
    form — so every *stored* id has an authority and remote vaults aren't a special case.
  - An optional name suffix on the UUID (`…fcdbb:personal`) is **accepted but ignored** —
    debug sugar only, never part of identity.
  - **Compositionally:** a Vault's id is `hypomnema://<host>/vaults/<uuid>`; a Note's id is
    that Vault URI **plus its vault-relative path**. "Which vault does this Note belong to"
    is therefore recoverable from the Note's id alone.
  - *Provisional — Hypomnema links aren't fully designed yet; the canonical-form choice is
    flagged for revisit in §6.*

This is why the §2 schema models the owning vault as a typed `vault` **reference** (not a
bare UUID string): Vault is a discrete entity, and a typed relationship is what ADR-003
wants for graph walks (Note → its Vault → its other Notes).

*Confirmed against the real tool:* every `hmn search` result carries exactly `vault`
(uuid) + `vault_name` + a vault-relative path — the precise components this URI is built
from. See `search-shape-notes.md`.

---

## 1. Frame

A `Note` is Hypomnema's view of a single Obsidian markdown file. Hypomnema **mirrors
Obsidian's open-meaning stance** (ADR-003, ADR-006): it exposes `Vault`, `Note`, and `Tag`
as primitives and preserves relational structure (backlinks, tags, frontmatter), but it
does **not** interpret frontmatter `type:` as creating typed subtypes, nor frontmatter
`status:` as an authoritative lifecycle. That stance does real work below — it's the reason
the `status` slot drops out.

The envelope has three parts, per the prior session's positions:

- a **JSON Schema** describing structure (§2),
- a sibling **presentation-hints** object describing display semantics (§3),
- an **envelope** wrapping both with routing fields (§4).

Field references throughout use **JSON Pointers**, never field names.

---

## 2. JSON Schema — structural fields

Structure only. No display semantics live here — that's the whole point of keeping hints
in a sibling object rather than as `x-` keywords inside the schema.

```json
{
  "$schema": "https://json-schema.org/draft/2020-12/schema",
  "type": "object",
  "additionalProperties": false,
  "properties": {
    "title":       { "type": "string" },
    "body":        { "type": "string" },
    "frontmatter": { "type": "object", "additionalProperties": true },
    "tags":        { "type": "array", "items": { "type": "string" } },
    "backlinks":   { "type": "array", "items": { "$ref": "#/$defs/reference" } },
    "references":  { "type": "array", "items": { "$ref": "#/$defs/resolved_reference" } },
    "created":     { "type": "string", "format": "date-time" },
    "modified":    { "type": "string", "format": "date-time" },
    "folder_path": { "type": "string" },
    "vault":       { "$ref": "#/$defs/reference" },
    "vault_path":  { "type": "string" }
  },
  "required": ["title", "body", "vault", "vault_path", "created", "modified"],
  "$defs": {
    "reference": {
      "type": "object",
      "additionalProperties": false,
      "properties": {
        "provider": { "type": "string" },
        "type":     { "type": "string" },
        "id":       { "type": "string" },
        "label":    { "type": "string" }
      },
      "required": ["provider", "type", "id"]
    },
    "resolved_reference": {
      "type": "object",
      "additionalProperties": false,
      "properties": {
        "marker":   { "type": "string" },
        "target":   { "$ref": "#/$defs/reference" },
        "label":    { "type": "string" },
        "resolved": { "type": "boolean" }
      },
      "required": ["marker", "resolved"]
    }
  }
}
```

**Annotations.**

- **`body` is markdown source**, carried verbatim with its `[[wiki-links]]` intact. The
  schema says nothing about *how* to render it — that's `content_types` in §3. Resolution
  of those links is Hypomnema's job, and the result lands in `references`, not in `body`.
- **`frontmatter` is an open object** (`additionalProperties: true`). This is the
  open-meaning stance made structural: Hypomnema vouches that frontmatter *exists* and is
  key/value, not for what any key *means*.
- **`backlinks` use `#/$defs/reference`** — the concrete `{ provider, type, id }` triple.
  Backlinks are notes that already point *here*; they're always resolved, so they get the
  plain reference, not the resolved-reference wrapper.
- **`references` use `#/$defs/resolved_reference`** — this is the body's outbound
  wiki-links *after* Hypomnema resolves them: the **sidecar**. `target` is optional and
  `resolved` is required precisely so a **dangling link** (a `[[…]]` pointing nowhere) can
  be represented as `{ marker, resolved: false }` with no target — Obsidian parity.
- **Identity is vault-scoped.** `vault_path` (provider-internal, vault-relative) is **not
  unique on its own** — `Decisions/streaming-protocol.md` could exist in any vault. Unique
  identity is the **`vault` reference plus `vault_path`**, which the instance-level `id`
  URI encodes in one string (§0, §5). `vault` is a typed `hypomnema.vault` reference rather
  than a bare UUID so the Note→Vault relationship is graph-walkable (ADR-003). `folder_path`
  is a separate *display* field (a presentable location), distinct from the
  identity-bearing `vault_path`. Required fields are the ones Hypomnema can always supply;
  `frontmatter`, `tags`, `backlinks`, `references`, `folder_path` may be empty/absent.
- **The `reference` triple is the load-bearing forward-looking piece.** It's deliberately
  shaped as `{ provider, type, id }` — where `id` is a vault-scoped `hypomnema://` URI
  (globally unique, §0), not a bare note path — so handoff-2's universal cross-provider
  reference envelope can adopt it wholesale. See §6.

---

## 3. Presentation hints

Sibling to the schema. Every value that points at data is a JSON Pointer. **Hints are
progressive** — a missing slot falls back to a renderer default; nothing here is required.

```json
{
  "title": "/title",
  "summary": { "strategy": "excerpt", "source": "/body", "max_chars": 200 },
  "icon": "file-text",
  "card_fields": ["/tags", "/modified"],
  "detail_fields": ["/vault", "/frontmatter", "/folder_path", "/created", "/modified", "/tags", "/backlinks"],
  "references": [
    { "content": "/body", "resolved_in": "/references" }
  ],
  "external_link": { "strategy": "provider_deeplink" },
  "content_types": [
    { "field": "/body", "type": "markdown" }
  ]
}
```

**Annotations, slot by slot.**

- **`title`** — a plain JSON Pointer to the title field. The simplest slot, and the
  baseline form every slot started as.
- **`summary`** — the discovery of this walkthrough. Notes have no summary *field*. Rather
  than invent one or push the logic into every client, a slot value may now be **either a
  JSON Pointer (field-ref) or a computed-strategy object**. Here: derive a 200-char excerpt
  from `/body`. This generalization is reusable by any field-less type, and it keeps the
  derivation rule *in the schema* (portable across web/native) rather than in each
  renderer.
- **`icon`** — `"file-text"` is an **abstract name**. The host maps abstract names to a
  concrete library (Lucide on web by default). An extension that wants a specific library
  namespaces it: `"octicons:repo"`. Note uses the abstract form; the namespaced form is the
  documented escape hatch for domain-specific iconography.
- **`status`** — **deliberately absent.** A status pill asserts a typed lifecycle the
  provider vouches for. Hypomnema's open-meaning stance refuses to read frontmatter
  `status:` as an authoritative enum, so it declares no status slot and renderers show no
  status pill for Notes. The instance in §5 *has* `frontmatter.status: "accepted"` and the
  hints still ignore it — that contrast is the point. See §6 for the gap this surfaces.
- **`card_fields` / `detail_fields`** — arrays of JSON Pointers selecting which fields
  surface in the compact card view vs. the expanded detail view. Pointers, per the
  field-reference position. (Open question on labeling — see §6.)
- **`references`** — maps a content field to its resolution sidecar: `/body`'s markers
  resolve into `/references`. This is the *reference-bearing* declaration, and it is
  **orthogonal to `content_types`** — see below.
- **`external_link`** — also a strategy object (same generalization as `summary`). A Note's
  external link is a deep-link derived from its **canonical `id`** (which already encodes
  vault + path), translatable to an `obsidian://` URL for "open in Obsidian." It takes no
  `id_source`: deriving from `/vault_path` alone would hit the very identity bug §0 fixes —
  the path isn't unique without its vault. The alternative — a pointer to `frontmatter.url`
  — would only work for notes that happen to carry that key, which open-meaning can't
  assume. The strategy form is the honest default.
- **`content_types`** — declares `/body` is `markdown`. Default for any unlisted string
  field is plain text.

**Why `content_types` and `references` are two slots, not one.** They answer different
questions about the same field. `content_types` says *how to render the field's text*
(markdown / plain / code). `references` says *which markers in the field resolve to
entities, and where the resolutions are*. They're orthogonal: a plain-text field can be
reference-bearing (e.g. an `@mentions` field), and a markdown field need not be (a plain
prose note with no links). Collapsing them would force a false coupling.

---

## 4. Envelope

Wraps schema + hints with the routing fields the host needs to dispatch a type declaration.

```json
{
  "envelope_version": "0",
  "type": "hypomnema.note",
  "type_version": "1.0.0",
  "provider": "hypomnema",
  "custom_renderer": null,
  "schema": { "...": "the JSON Schema from §2" },
  "presentation": { "...": "the hints object from §3" }
}
```

**Annotations.**

- **`envelope_version`** — the version of the *schema-language envelope itself*. Ties to
  ADR-010's explicit protocol versioning from v0. Bumped when the envelope grammar changes
  (e.g. the day a new top-level routing field is added).
- **`type_version`** — semver of *this type's declaration* (`hypomnema.note@1.0.0`).
  Bumped when Note's schema or hints change. Distinct concern from `envelope_version`:
  one tracks the language, the other tracks the dialect a provider speaks in it.
- **`type`** — namespaced type identifier. `provider.type`, dotted, so `hypomnema.note`
  never collides with a future `github.repo` or `linear.project`.
- **`provider`** — which provider owns and serves this type. Redundant with the `type`
  prefix today, kept explicit because the host routes search/serialize/suggest calls by
  provider and shouldn't have to parse the type string to do it.
- **`custom_renderer`** — ADR-012's escape hatch, at the envelope level. `null` means *use
  the generic schema-driven renderer*. A non-null value names a renderer the client may
  implement; clients that don't fall back to schema-driven rendering. Note needs no custom
  renderer, so it's `null` — which is the v0 default for everything.

---

## 5. Serialized instance

Per ADR-012, the wire carries **schema + data, not pre-rendered content**, and the type
*declaration* (§4) is sent once. An **instance is lean**: `{ type, id, data }`, referencing
the declaration by `type`.

```json
{
  "type": "hypomnema.note",
  "id": "hypomnema://localhost/vaults/019dd737-8435-7db3-937a-0884d6b0ce63/Decisions/streaming-protocol.md",
  "data": {
    "title": "Streaming Protocol Decision",
    "body": "We chose server-authoritative streaming (see [[ADR-001]]) over the client-persisted model in [[Iris]].\n\nOpen thread: [[Mercure vs FrankenPHP]].\n\nThe event log is the wire protocol.",
    "frontmatter": {
      "aliases": ["streaming decision"],
      "status": "accepted"
    },
    "tags": ["architecture", "streaming"],
    "vault": {
      "provider": "hypomnema",
      "type": "hypomnema.vault",
      "id": "hypomnema://localhost/vaults/019dd737-8435-7db3-937a-0884d6b0ce63",
      "label": "personal"
    },
    "backlinks": [
      {
        "provider": "hypomnema",
        "type": "hypomnema.note",
        "id": "hypomnema://localhost/vaults/019dd737-8435-7db3-937a-0884d6b0ce63/Decisions/event-sourcing.md",
        "label": "Event Sourcing"
      }
    ],
    "references": [
      {
        "marker": "[[ADR-001]]",
        "target": { "provider": "hypomnema", "type": "hypomnema.note", "id": "hypomnema://localhost/vaults/019dd737-8435-7db3-937a-0884d6b0ce63/adr/adr-001.md" },
        "label": "ADR-001",
        "resolved": true
      },
      {
        "marker": "[[Iris]]",
        "target": { "provider": "hypomnema", "type": "hypomnema.note", "id": "hypomnema://localhost/vaults/019dd737-8435-7db3-937a-0884d6b0ce63/projects/iris.md" },
        "label": "Iris",
        "resolved": true
      },
      {
        "marker": "[[Mercure vs FrankenPHP]]",
        "label": "Mercure vs FrankenPHP",
        "resolved": false
      }
    ],
    "created": "2026-04-02T14:31:00Z",
    "modified": "2026-05-18T09:12:00Z",
    "folder_path": "/Decisions",
    "vault_path": "Decisions/streaming-protocol.md"
  }
}
```

**Annotations.**

- **Identity = vault + path.** The instance `id`
  (`hypomnema://localhost/vaults/019dd737-…/Decisions/streaming-protocol.md`) is the
  canonical entity identity the rest of the system pins, cites, and dedupes by — it encodes
  the **vault UUID and the vault-relative path together**. `data.vault_path`
  (`Decisions/streaming-protocol.md`) is *not* unique on its own. The `data.vault` reference
  is the denormalized, graph-walkable form of the same fact: its `id` is the Vault's URI,
  and the Note's `id` is exactly that Vault URI with `vault_path` appended. The `personal`
  label on the vault reference is the unstable debug name (§0) — never used for identity or
  matching.
- **The sidecar in action.** `body` keeps its raw `[[…]]` markers. `references` is the
  resolution: two resolved targets (each a full triple) and **one dangling link**
  (`[[Mercure vs FrankenPHP]]`, `resolved: false`, no `target`). A renderer walks `body`,
  and for each marker found in `references` swaps in a citation affordance — or, for the
  unresolved one, a broken-link style. The model-facing serialization can do the same to
  decide what to expand.
- **The `status` contrast.** `frontmatter.status` is `"accepted"`, fully present in the
  data — and the §3 hints still declare no status slot, so no pill renders. The information
  isn't lost (it's in `frontmatter`, surfaced via `detail_fields`); it's just not promoted
  to a typed lifecycle. This is the open-meaning stance visible on a single instance.

---

## 6. Decisions & gaps

### The five handoff questions, answered

1. **Does the envelope shape hold up?** Yes. The schema / hints / routing split absorbed a
   real type without contortion, and keeping hints in a sibling object (not `x-` keywords)
   meant the JSON Schema stayed a clean, standard, independently-validatable artifact. Two
   genuine bends (below): hint slot *values* had to become polymorphic, and the walkthrough
   exposed that a Note has **no unique identity without its Vault** — fixed by a typed
   `vault` reference plus vault-scoped `hypomnema://` URIs (§0), not by changing the
   envelope grammar.

2. **Are the presentation-hint slots sufficient?** Mostly — with one removal, one
   generalization, and two candidate additions:
   - **Removed for open providers: `status`** (locked decision — see below).
   - **Generalized: slot values are pointer OR strategy.** Forced by `summary`, reused by
     `external_link`. This is the walkthrough's main vocabulary change.
   - **Candidate gap — temporal-field semantics.** `created`/`modified` ride in
     `card_fields`/`detail_fields` as plain pointers, so a renderer can't tell they're
     timestamps deserving relative-time display ("3 days ago") vs. literal strings. A
     per-field `format` hint or a `timestamps` slot would fix it. Not adopted yet; flagged.
   - **Candidate gap — field labeling.** `card_fields`/`detail_fields` are bare pointers
     with no human label, so the renderer must derive labels from the pointer or from
     schema annotations. Works for `tags`/`modified`; gets awkward for
     `/frontmatter/some_key`. A `{ field, label }` form may be needed later.

3. **How is markdown-with-references expressed?** With the two orthogonal slots: `body` is
   declared `content_type: markdown` (how to render the text) **and** reference-bearing via
   `references: [{ content: "/body", resolved_in: "/references" }]` (which markers resolve,
   and where). Keeping them separate is right — proven by the fact that backlinks are a
   reference relationship with *no* associated content field at all, and a plain-text field
   could be reference-bearing without being markdown.

4. **How are relationships (backlinks, tags) declared — structurally, in hints, or both?**
   **Both, with distinct jobs.** The schema declares *shape*: `backlinks` as a typed
   reference array, `tags` as a string array, `references` as the resolved sidecar. The
   hints declare *placement*: backlinks/tags appear in `detail_fields`, body references via
   the `references` slot. Structure says what exists; hints say where it shows. Neither
   layer alone is sufficient.

5. **What does the sidecar look like on an instance?** An array of `resolved_reference`:
   `{ marker, target?, label?, resolved }`. Resolved entries carry a full `{ provider,
   type, id }` target; dangling entries carry `resolved: false` and no target. See §5.

### Identity & the Vault prerequisite (review correction)

The first draft gave a Note the id `vault://Decisions/streaming-protocol.md` — wrong: a
vault-relative path isn't unique across vaults. Corrected here:

- **Vault is a discrete primitive** (§0). A Hypomnema instance connects to zero or more
  vaults; every Note belongs to exactly one.
- **Identity = `vault` + `vault_path`**, encoded canonically as a vault-scoped
  `hypomnema://<host>/vaults/<uuid>/<path>` URI. The vault UUID is identity; the vault
  *name* is unstable debug sugar.
- **Modeled as a typed `vault` reference** on the Note, not a bare string, so Note→Vault is
  graph-walkable (ADR-003), and a Note's id composes as `<vault-uri>/<vault_path>`.
- **Scope:** only the slice of Vault that Note's identity needs is defined (§0); the full
  Vault type envelope (schema + hints + routing) is deferred to its own pass.

### Decisions locked this session

- **`status` omitted from Note.** A status pill implies a typed lifecycle the provider
  vouches for; Hypomnema's open-meaning stance refuses to read frontmatter `status:` that
  way. **Gap surfaced:** `status` is a real, useful slot — but it belongs to providers that
  *impose* typed meaning (a future `Linear.Issue`, a typed `ArchitectureDecision`), not to
  open ones. The slot list must therefore mark which slots are universal vs.
  typed-meaning-only (§7).
- **`summary` strategy form.** Slot values may be a JSON Pointer or a computed-strategy
  object (`{ strategy, source, … }`). Note's summary is an excerpt of `/body`.

### Recommendations baked in (overridable)

- **Lean instances** (`{ type, id, data }`), declaration sent once — ADR-012.
- **Two version fields** — `envelope_version` (the language) and `type_version` (the
  provider's dialect).
- **Reference triple `{ provider, type, id }` adopted now**, flagged as the candidate
  **universal cross-provider reference envelope** for handoff-2 to formalize. Backlinks use
  the plain triple; body references wrap it in `resolved_reference` to carry `marker` +
  `resolved`. Handoff-2 should decide whether `resolved_reference` is the universal shape
  with the plain triple as its degenerate (always-resolved) case, or whether they stay
  distinct.

### Gaps to carry forward

- `status` / open-meaning tension → resolved here by omission; informs ADR-013's slot
  taxonomy (universal vs. typed-meaning-only).
- Temporal-field semantics (a `format` hint or `timestamps` slot) → not adopted; revisit
  when relative-time display is built.
- Field labeling for `card_fields`/`detail_fields` → revisit if bare-pointer labels pinch.
- The reference triple → direct input to handoff-2.
- Full **Vault type envelope** deferred → its own walkthrough; §0 defines only the identity
  anchor Note needs.
- **Canonical `hypomnema://` URI form** is provisional — explicit-authority vs.
  empty-authority shorthand, the ignored `:name` suffix, and remote-host handling all
  need settling when Hypomnema links are designed.

---

## 7. Authoritative slot list (exercised)

| Slot | Value form | Scope |
|---|---|---|
| `title` | JSON Pointer | universal |
| `summary` | JSON Pointer **or** strategy object | universal |
| `icon` | abstract name (`file-text`), namespaceable (`octicons:repo`) | universal |
| `status` | `{ field, variants }` | **typed-meaning providers only** — not declared by open providers like Hypomnema |
| `card_fields` | `[JSON Pointer]` | universal |
| `detail_fields` | `[JSON Pointer]` | universal |
| `references` | `[{ content, resolved_in }]` | universal (empty when the type has no reference-bearing fields) |
| `external_link` | JSON Pointer **or** strategy object | universal, optional |
| `content_types` | `[{ field, type }]` | universal, optional (default: plain text) |

**Candidate slots, not yet adopted** (surfaced in §6, deferred to ADR-013 or first real
need): per-field `format` / a `timestamps` slot for temporal display; a labeled
`{ field, label }` form for card/detail fields.
