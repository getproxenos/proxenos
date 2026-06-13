# Schema Language — Vault Walkthrough (Result)

The deferred companion to `note-walkthrough.md`. The Note pass defined the slice of
Vault that Note's identity depends on (§0 there) and explicitly punted the full envelope
to its own walkthrough; this is that walkthrough. The same drill: full envelope (JSON
Schema + presentation hints + routing), a serialized instance, a capability surface, and
a closing pass that records what bent and what stayed.

This document feeds `artifact-capabilities-walkthrough.md` (which already references a
`hypomnema.vault_folder` target kind without defining it — §6 settles that), and it
closes the §0/§6 Vault loose ends from `note-walkthrough.md`.

---

## 1. Frame

A `Vault` is Hypomnema's view of a single Obsidian vault: a discrete, named, addressable
container of Notes. A Hypomnema instance connects to **zero or more** vaults; every Note
belongs to **exactly one**. Vault is a first-class primitive (ADR-003), peer to Note and
Tag — not an attribute of a Note.

Two stances the read pass already locked, carried in unchanged:

- **Open meaning.** Hypomnema preserves vault structure (notes, tags, backlinks,
  frontmatter) but does not interpret a vault's *purpose*. A vault is not "the work
  vault" or "the journal vault" to Hypomnema — those are user conventions on the name.
- **Identity is the UUID; the name is debug sugar.** A vault's canonical id is
  `hypomnema://<host>/vaults/<uuid>`. The name (`personal`) is rename-able and unstable;
  it surfaces only as a label.

What Vault adds that Note couldn't carry alone:

- A **stable handle** for the "which vault" half of every Note identity (§5 of the read
  pass) — graph walks `Note → Vault → other Notes in same Vault` resolve through a
  concrete entity, not a bare UUID.
- A place to express **vault-level configuration that affects Note writability**: which
  folder is the designated conversation-outputs target, whether the vault is read-only,
  whether `.trash` is the soft-delete destination. The capability surface (§6) hangs off
  the Vault, because availability is a per-vault property.
- A **target kind** (`hypomnema.vault_folder`, §7) — the thing a Note create points at.
  Defining Vault first lets the folder reference compose cleanly instead of being a
  free-floating string.

The envelope is the same three-part split: JSON Schema (§2), presentation hints (§3),
routing envelope (§4), capability surface (§6).

---

## 2. JSON Schema — structural fields

```json
{
  "$schema": "https://json-schema.org/draft/2020-12/schema",
  "type": "object",
  "additionalProperties": false,
  "properties": {
    "name":             { "type": "string" },
    "host":             { "type": "string" },
    "uuid":             { "type": "string", "format": "uuid" },
    "active":           { "type": "boolean" },
    "read_only":        { "type": "boolean" },
    "outputs_folder":   { "type": "string" },
    "trash_folder":     { "type": "string" },
    "note_count":       { "type": "integer", "minimum": 0 },
    "last_indexed":     { "type": "string", "format": "date-time" }
  },
  "required": ["name", "host", "uuid", "active", "read_only"]
}
```

**Annotations.**

- **`uuid` + `host` are the identity components, surfaced as data.** The instance `id`
  (§5) is the composed URI `hypomnema://<host>/vaults/<uuid>`. The schema carries the
  *parts* separately because (a) renderers may want to display the host without parsing
  the URI, and (b) the URI form is provisional (§7 of the read pass) — keeping the
  components addressable means we can change the canonical-form rules without breaking
  field references in hints.
- **`name` is data, not identity.** Confirmed by every `hmn search` result carrying
  `vault` (uuid) **and** `vault_name` (e.g. `personal`, `claude`) — the name rides
  alongside identity but is rename-able. A renderer may use it as the title; the host
  must not use it for matching.
- **`active` is the runtime state of the Hypomnema↔vault connection**, not "does the
  vault exist." A vault may be configured but currently unreachable (sync paused,
  filesystem unmounted); `active: false` means Hypomnema won't serve search or write
  requests against it. This is the per-vault analogue of ADR-010's per-extension
  availability negotiation, except it lives in the entity because a single Hypomnema
  instance may have many vaults with independent states.
- **`read_only` is a Vault-level posture**, not a per-Note one. It's what causes a
  write-enabled `hypomnema.note` to report create/update **not live** for *this* vault
  even though the type's contract supports them (see the artifact-capabilities walkthrough's
  "runtime availability" point — §5 there). Concretely: vaults attached for reference (a
  client's published vault, a read-only sync mount) declare `true`; the personal vault
  declares `false`.
- **`outputs_folder` is the designated conversation-outputs folder.** This is the
  vault-relative path a Note create defaults to when no other target is named. It is
  **optional** at the schema level — a vault may be writable without designating an
  outputs folder, in which case `hypomnema.note.create` is live but every invocation must
  supply a `target` explicitly. (The artifact-capabilities walkthrough's §5 phrased this
  as "a Hypomnema instance with no designated outputs folder reports create not live" —
  this walkthrough refines that: create is live, but the *default* target is absent.
  Whether the host degrades the affordance to "save needs a folder choice" or hides it
  entirely is a host policy, not a vault declaration.)
- **`trash_folder` is the soft-delete destination.** Matches Obsidian's own `.trash`
  convention by default but is declared per-vault because users override it. Drives the
  `delete.mode: soft` semantic from the artifact-capabilities walkthrough's §3 — when the
  host needs to warn ("moves to trash" vs. "permanently deletes"), the trash folder is
  what tells it whether soft is even configured.
- **`note_count` and `last_indexed` are stats, not identity.** Both are
  provider-derived: the count comes from Hypomnema's index, `last_indexed` from its sync
  state. They're optional because a freshly-attached vault may not have completed its
  first index yet; once present, a renderer can show "1,247 notes · indexed 3m ago" in
  the card view. *Provisional — see §7.*
- **No `path` / on-disk location field.** Where Obsidian Sync drops the vault on the
  filesystem is a deployment concern, not an entity property. Surfacing it would leak
  the host's filesystem layout to clients, and it's not stable across the
  Coolify-deployed/personal-laptop split (overview.md). Hypomnema knows; the entity
  doesn't carry it.
- **No `tags` array.** A vault's tags are *aggregable* (the union of tags across its
  notes) but not *intrinsic* to the vault — they're a query against its Notes, not a
  field. Same reasoning kept `tags` out of the Vault schema that kept `references` out of
  every type's identity: derived facts live in queries, not in the entity body.

---

## 3. Presentation hints

```json
{
  "title": "/name",
  "summary": {
    "strategy": "template",
    "template": "{note_count} notes · indexed {last_indexed}",
    "fallback": { "strategy": "literal", "text": "Obsidian vault" }
  },
  "icon": "archive",
  "card_fields": ["/host", "/note_count", "/last_indexed"],
  "detail_fields": ["/uuid", "/host", "/active", "/read_only", "/outputs_folder", "/trash_folder", "/note_count", "/last_indexed"],
  "external_link": { "strategy": "provider_deeplink" },
  "content_types": []
}
```

**Annotations, slot by slot.**

- **`title` → `/name`.** Bare JSON Pointer — the simplest slot form. The Vault's display
  name is its `name` field, never its UUID. The id and name divergence (§2) is
  deliberately hidden from the title: users think in names.
- **`summary` — a new strategy form, `template`.** Notes used `excerpt` (derive from a
  body field); Vault has no body, but it does have a natural summary shape: "N notes,
  indexed X ago." Rather than push that string-building into every renderer, declare it
  in the schema as a `template` strategy with field interpolation against JSON Pointers.
  `fallback` is itself a strategy object — if `note_count` and `last_indexed` aren't
  present yet (fresh vault, first-index pending), the literal `"Obsidian vault"` ships
  instead. **This is a real slot-vocabulary extension** — Note's walkthrough surfaced
  `pointer | strategy` polymorphism, and Vault forces a second concrete strategy (`template`
  joining `excerpt`). See §7 for the gap this raises about strategy taxonomy.
- **`icon` — `archive`** (abstract name; Lucide on web by default per ADR-013 / read
  pass §3). Vaults read more like a container/archive than a single document; `folder`
  was the obvious alternative but reads as too lightweight for the
  thousand-notes-with-a-graph thing a vault actually is.
- **`status` — deliberately absent**, same reasoning as Note: Hypomnema is an
  open-meaning provider, and `status` is the typed-meaning-only slot from ADR-013's slot
  taxonomy. `active` and `read_only` are *facts about the connection*, not a typed
  lifecycle the provider vouches for — a renderer that wants to show "Active · Read-only"
  composes that from the `detail_fields` data without a status pill. The contrast is
  worth calling out: Vault has more booleans than Note does, and it still doesn't
  declare a status slot. The slot's gating criterion is "does the provider impose typed
  meaning?" — not "does the type have boolean state?"
- **`card_fields` / `detail_fields` — bare JSON Pointers, no labels.** Same as Note.
  This walkthrough makes the field-labeling gap (Note §6) bite harder: a card showing
  `/host`, `/note_count`, `/last_indexed` as three bare pointers wants human labels
  ("Host", "Notes", "Indexed") that the renderer has to invent from the pointer name.
  Tracked in §7.
- **`external_link: { strategy: "provider_deeplink" }`.** Mirrors Note's choice: the
  Vault has no `frontmatter.url` and no canonical web URL, so the deep-link strategy
  derives an `obsidian://open?vault=<name>` URL from the id. The `vault=<name>` form is
  what Obsidian's own URL scheme accepts (it indexes vaults by name on the local
  install), which is exactly why the unstable name still has to ride alongside identity:
  the deep-link can't use the UUID. This is a soft-but-real reason to keep `name` in
  the schema rather than hiding it.
- **`content_types: []`.** Explicit empty — Vault has no body-text field, so there is
  nothing to declare a content type for. Note had `[{ field: "/body", type: "markdown" }]`;
  Vault genuinely has nothing to put here, and that's the right outcome. (`content_types`
  is ADR-013-universal-optional, so this could also be omitted; declaring it empty is a
  pedagogical choice for the walkthrough.)
- **`references: []` (omitted).** Vault has no outbound entity references in its own
  body — backlinks/references are Note-shaped, not Vault-shaped. A user querying "what
  notes live in this vault" runs a search; that's a *query-shaped reference* (ADR-013)
  the *consumer* attaches, not a declared field on the Vault entity. Same reasoning as
  the no-`tags` decision in §2.

---

## 4. Envelope

```json
{
  "envelope_version": "1",
  "type": "hypomnema.vault",
  "type_version": "1.0.0",
  "provider": "hypomnema",
  "custom_renderer": null,
  "schema": { "...": "the JSON Schema from §2" },
  "presentation": { "...": "the hints object from §3" },
  "capabilities": { "...": "the capability surface from §6" }
}
```

**Annotations.**

- **`envelope_version` is `"1"`, not `"0"`** — the read pass's Note declared `"0"`. The
  bump is the one ADR-013 already documents (per the post-capabilities update): adding
  the top-level `capabilities` routing field is the additive grammar change that lifted
  the envelope to `"1"`. Vault is the first walkthrough to actually carry `capabilities`,
  so it's also the first one to declare `"1"` honestly. A `"0"` envelope is interpreted
  as read-only; a `"1"` envelope may include `capabilities` (and is still read-only when
  it doesn't).
- **`type` is `hypomnema.vault`** — `provider.type` dotted form, no collision with a
  future `core.vault` or `linear.project_vault` (if such a thing existed).
- **`type_version` is `1.0.0`**, fresh — distinct from Note's `1.0.0` because they're
  independent dialects. A change to Vault's schema or hints bumps this; a change to the
  envelope grammar (e.g. adding a new top-level routing field) bumps `envelope_version`.
- **`custom_renderer: null`** — same v0 default as Note. The generic schema-driven
  renderer is sufficient for "icon + name + stats + deep-link." A bespoke renderer (graph
  view of the vault's note relationships, say) would be a `custom_renderer: "vault_graph"`
  value some future client implements; clients that don't fall back to the generic.

---

## 5. Serialized instance

```json
{
  "type": "hypomnema.vault",
  "id": "hypomnema://localhost/vaults/019dd737-8435-7db3-937a-0884d6b0ce63",
  "data": {
    "name": "personal",
    "host": "localhost",
    "uuid": "019dd737-8435-7db3-937a-0884d6b0ce63",
    "active": true,
    "read_only": false,
    "outputs_folder": "Conversations",
    "trash_folder": ".trash",
    "note_count": 1247,
    "last_indexed": "2026-06-12T13:42:00Z"
  }
}
```

**Annotations.**

- **Identity is `hypomnema://localhost/vaults/<uuid>` — no path suffix.** This is the
  composition rule the read pass committed to (§0 there): a Note's id is its Vault's id
  plus `/<vault_path>`. Vault's id is the prefix; Note's is the prefix plus the path
  tail. Cleanly recoverable in either direction — given a Note id, slice off the path
  segments to recover its Vault id; given a Vault id, list its Notes by appending paths.
- **`name: "personal"` matches the `:personal` debug suffix accepted-but-ignored in
  Note's URIs (read pass §0).** The instance carries the same name in its data field
  that the URI might carry as a suffix; either way, it's debug sugar, never identity.
- **`host: "localhost"`** is the explicit-authority form. A remote Hypomnema would
  surface as `host: "hypomnema.example.com"` and the id would change to match. This is
  why the URI's empty-authority shorthand (`hypomnema:///vaults/<uuid>`) is normalized to
  the explicit form on input — every stored id has an authority, remote vaults are not a
  special case.
- **`active: true`, `read_only: false`, `outputs_folder: "Conversations"`** — together
  these make this vault eligible for Note creation via `hypomnema.note.create`. A second
  vault with `read_only: true` (the `claude` vault from search-shape-notes, say —
  populated by the export-to-vault project, never written into by conversation) would
  appear as a sibling instance with the same envelope but different data, and
  `hypomnema.note.create` would report not-live for it.
- **`note_count: 1247`** — derived. Updates as Hypomnema's index updates, doesn't
  invalidate identity. The card-summary template (`"{note_count} notes · indexed
  {last_indexed}"`) renders as `"1247 notes · indexed 2026-06-12T13:42:00Z"`, which
  surfaces the temporal-format gap from Note §6 again (`last_indexed` should display as
  "5 minutes ago", not as a raw timestamp). Carried forward to §7.

---

## 6. Capability surface

**Vault declares no capability surface.** A user does not create, update, delete, or
rename a vault from a conversation. Vaults are configured at the Hypomnema deployment
level: filesystem mounts, Obsidian Sync hookups, Compose service config. That
configuration is outside the entity system entirely.

```json
"capabilities": {}
```

(Equivalently: omit the `capabilities` field. An empty map and an absent field both mean
"every operation unsupported" per ADR-013's capabilities decision — the read-only default
the ADR commits to for any type that doesn't speak up.)

**Why this is the right call, not a deferral.**

- **The motivating workflow gap is Note creation, not Vault creation.** The
  artifact-capabilities walkthrough's §1 frames the problem as "an answer worth keeping
  is dead the moment the conversation ends" — the answers are *notes*, not *vaults*. A
  user with zero vaults configured is a user who hasn't set up Hypomnema yet; that's a
  different surface (settings, onboarding) than the conversation-driven write surface
  this system is building.
- **Vaults have lifecycle, but it's installation-shaped, not document-shaped.** Adding
  a vault means picking a sync destination and waiting for an initial index — minutes
  of provider-side work, not a JSON-RPC call. Forcing that through a `create` operation
  would either (a) lie about completion (return an id for a vault that doesn't have its
  notes yet) or (b) introduce async-write semantics that no other capability needs.
- **Read-only vaults already work via runtime availability.** The Vault's `read_only`
  field plus `hypomnema.note.create`'s runtime not-live report (artifact-capabilities §5)
  cover the "this vault doesn't accept writes" case without any Vault-level capability
  declaration.

**What Vault does instead.** The fields in §2 — `read_only`, `outputs_folder`,
`trash_folder` — are the Vault's contribution to the *Note's* capability surface. They
let `hypomnema.note.create` know whether it's live, where to default, and what soft-delete
means, without Vault itself needing to claim any writes. This is the cleanest separation
the model affords: Vault declares *facts*, Note declares *operations*, and the
operation's availability is computed from the facts at handshake time.

---

## 7. Decisions & gaps

### Decisions locked this session

- **Vault is read-only at the entity layer.** No capability surface; `capabilities: {}`
  (or omitted). Configuration of vaults lives outside the entity write path.
- **Vault identity is `hypomnema://<host>/vaults/<uuid>` with no path tail.** Confirms
  the composition rule from the read pass: Note id = Vault id + `/<vault_path>`.
- **A second strategy form, `template`,** joins `excerpt` from the Note pass — used by
  Vault's `summary` slot. Strategy objects are now plural enough that the vocabulary is
  starting to grow; flagged as a gap below.
- **`hypomnema.vault_folder` — the target kind referenced by `hypomnema.note.create`
  (artifact-capabilities walkthrough §5) — is *not* a separate entity type.** It's a
  **typed-pair reference** that composes a Vault id with a vault-relative folder path:

  ```json
  {
    "kind": "hypomnema.vault_folder",
    "vault": {
      "provider": "hypomnema",
      "type": "hypomnema.vault",
      "id": "hypomnema://localhost/vaults/019dd737-…",
      "label": "personal"
    },
    "folder": "Conversations/Decisions"
  }
  ```

  The Vault half is a real entity reference (universal triple, ADR-013). The `folder`
  half is a vault-relative string with no independent identity — there's no
  `hypomnema.folder` entity, and creating one would invent a primitive that Hypomnema
  doesn't have (Obsidian folders are filesystem directories, surfaced only as path
  prefixes). The composite is what a create *target* needs, and it's the smallest shape
  that gets there. **Gap:** target-kind declarations are otherwise a free-form string in
  ADR-013; this is the first concrete one, and it suggests target kinds may need their
  own small registry — see below.

### Gaps surfaced

- **Strategy taxonomy is now multi-valued.** `excerpt` (Note), `template` (Vault),
  `provider_deeplink` (both). The strategy object is starting to behave like a small DSL;
  it doesn't yet need a versioned schema, but a list of "v0 supported strategies" is now
  a real artifact ADR-013 should enumerate. Candidate v0 set: `excerpt`, `template`,
  `provider_deeplink`, `literal`. Each takes its own params; an unknown strategy is a
  renderer fall-back to default behavior, mirroring the `custom_renderer` posture.
- **Target-kind registry.** `hypomnema.vault_folder` is the first concrete target kind
  for a write operation. Its shape (typed reference + auxiliary path) is reasonable but
  ad-hoc — there's no place that says "target kinds are declared like *this*." If a
  second provider needs a write target (a `github.repo_branch`, say), the precedent set
  here should be reusable. Candidate: a target-kind declaration lives in the *type's*
  envelope (the way capabilities do), keyed by `kind`, with its own schema. Deferred —
  flag for ADR-014 or a future revision of ADR-013.
- **`hmn` does not expose a "list vaults" endpoint** as a search modality
  (search-shape-notes §2 only covers `filesystem` / `content` / `semantic`). Vault
  instances are populated by Hypomnema's handshake-time inventory, not by a per-request
  search. This means there's no `evidence` shape for Vault search results (you don't
  search *for* vaults; you list them at startup). The search-shape envelope still works
  — a "vaults" listing would carry `evidence.kind = "none"` — but it suggests an
  `inventory` or `list` modality may be worth adding alongside the three search ones, at
  which point Vault becomes its first consumer.
- **Temporal-format gap, again.** `last_indexed` renders as a raw timestamp in the
  card summary template (`"…indexed 2026-06-12T13:42:00Z"`) when the user actually wants
  "5 minutes ago." This is the same gap Note flagged (§6 there); Vault makes it bite a
  second time. Strong candidate for a per-field `format` hint or a `timestamps` slot in
  ADR-013's next revision.
- **Field-labeling gap, again.** `card_fields: ["/host", "/note_count", "/last_indexed"]`
  needs human labels the renderer must invent. Bare pointers were defensible for
  Note's card (one or two fields, name-derivable); Vault's card has more, and the lack
  of labels is more obviously a pinch. Same fix as Note flagged: a `{ field, label }`
  form for card/detail entries.
- **`active` / `read_only` boolean rendering.** No slot describes how a boolean field
  should display. Renderers will guess (badge, pill, check, text). A `content_types`
  entry like `{ field: "/read_only", type: "boolean_badge" }` is one plausible direction;
  not adopted yet. Worth watching as more boolean-bearing types arrive.
- **No `path` / on-disk location on Vault.** Decision is locked (§2) but worth
  re-examining if a "reveal in Finder" affordance is ever wanted. For now,
  `external_link.provider_deeplink` (open in Obsidian) covers the user-visible escape
  hatch.

### Recommendations baked in (overridable)

- **Lean instances**, declaration sent once — ADR-012, same as Note.
- **`envelope_version: "1"`** because Vault is the first walkthrough authored against
  the post-capabilities envelope grammar. Read-only types may still publish `"0"` for as
  long as they want; mixing is supported.
- **`hypomnema.vault_folder` as a typed-pair composite** rather than a free-standing
  entity type. Cleanest minimal shape; revisit only if folders need independent
  lifecycle (renames, deletion as a write op, attachment as a context member).

### Cross-references

- Read pass §0 (Vault prerequisite for Note identity) — Vault's id composition rule and
  the unstable-name discipline carried in here unchanged.
- `search-shape-notes.md` §2 — `vault` (uuid) + `vault_name` + path on every search
  result is the empirical confirmation of §2's field set.
- `artifact-capabilities-walkthrough.md` §5 — the `hypomnema.vault_folder` target kind
  referenced there is the one defined in §7 here, and the "runtime availability" report
  that walkthrough relies on is what reads Vault's `active` / `read_only` /
  `outputs_folder` at handshake time.
- ADR-013 — the slot vocabulary; this walkthrough exercises it with no new universal
  slots, two new strategy forms (`template`, `literal`), and one new target kind.

---

## 8. Authoritative slot list (re-exercised)

No new universal slots beyond Note's. Same scope column applies.

| Slot | Value form | Vault uses |
|---|---|---|
| `title` | JSON Pointer | `/name` |
| `summary` | Pointer **or** strategy object | `{ strategy: "template", template: "…", fallback: { strategy: "literal", … } }` |
| `icon` | abstract name | `archive` |
| `status` | `{ field, variants }` | **not declared** (open-meaning provider) |
| `card_fields` | `[JSON Pointer]` | `/host`, `/note_count`, `/last_indexed` |
| `detail_fields` | `[JSON Pointer]` | full field set minus `/name` |
| `references` | `[{ content, resolved_in }]` | **omitted** (no reference-bearing fields) |
| `external_link` | Pointer **or** strategy object | `{ strategy: "provider_deeplink" }` |
| `content_types` | `[{ field, type }]` | `[]` (no body-text field) |

**Strategy vocabulary exercised so far** (across Note + Vault, candidate v0 set for
ADR-013): `excerpt`, `template`, `literal`, `provider_deeplink`.

**Target kinds exercised so far** (referenced by `capabilities.create.target.kind`):
`hypomnema.vault_folder` (typed-pair composite — Vault reference + vault-relative folder
string).
