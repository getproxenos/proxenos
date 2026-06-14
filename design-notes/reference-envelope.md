# Universal Reference Envelope

The canonical cross-provider reference shape. One envelope serves every place an entity
points at another entity — Note backlinks, body links and their resolution sidecar,
Context Set members, search result rows, citations, and (eventually) transcluded
content. ADR-013 already named the universal reference triple `{ provider, type, id }`
and the `resolved_reference` wrapper used in body sidecars; this document settles which
of those two is canonical, what the cross-provider `id` contract is, and reserves the
slot that keeps transclusion an additive ADR rather than a wire-format migration.

**Source open question:** *"Whether `resolved_reference` is the universal shape with the
plain triple as its degenerate (always-resolved) case, or whether the two stay
distinct"* — ADR-013, open questions.
**Anchored by:** ADR-003 (typed entities / provider registry), ADR-010 (host ↔ extension
boundary), ADR-013 (schema language and the universal reference triple).
**Inputs:** `note-walkthrough.md` §2 (reference triple) and §5 (resolution sidecar),
`context-set-walkthrough.md` §2 (reference + query members), `search-shape-notes.md` §4
(result `ref`), `transclusion-notes.md` (the deferred design that consumes this).
**Downstream:** the transclusion ADR (when revived) — consumes the reserved
`expansion` slot; the Phase 0.x vertical-slice serialization — references serialize
through this envelope; the ADR-013 amendment registering both as first-class concepts.

---

## 1. Frame

Before this document there were *three near-duplicates* in flight:

- The plain triple `{ provider, type, id, label? }` (Note backlinks, Context Set
  reference members, search-result `ref`).
- The `resolved_reference` wrapper `{ marker, target?, label?, resolved }` (Note body
  sidecar — carries the `[[wiki-link]]` marker and the dangling case).
- The recurring open question, "may a reference carry a partial hydrated snapshot so a
  renderer can draw a card without a follow-up fetch?" (search-shape §7).

Each picked a slightly different vocabulary, and each held a piece of the picture. The
goal here is exactly one envelope that subsumes all three populations without forcing a
field on every caller that doesn't need it. The next four sections answer the five
questions raised by the reference-envelope handoff, each with the decided position **and
the ruled-out alternative**. §6 defines the envelope formally. §7 covers the canonical
serialized examples (resolved / dangling / snapshot-bearing). §8 reserves the
`expansion` slot.

---

## 2. One shape or two?

**Decision.** *Enriched as universal.* The `resolved_reference` envelope is the canonical
universal reference. The plain triple `{ provider, type, id }` is its degenerate,
always-resolved case (`resolved: true`, no `marker`, no `snapshot`). One type, two
populations.

**Why.** The plain triple is a strict subset of `resolved_reference`'s required fields,
and every field `resolved_reference` adds is either optional or universally meaningful
(`resolved` is meaningful even for callers that never dangle — they always send `true`).
Collapsing keeps resolver/renderer dispatch uniform: the host always looks at
`provider` + `type` first, then walks the rest of the envelope by checked-presence. Two
shapes would force every consumer (search, context-set member, transclusion expander,
prompt serializer) to branch on shape before reaching the dispatch step, and would push
the "is this dangling?" question into a type the type system says cannot dangle.

**Ruled out.** *Keep them distinct — plain triple for "always resolved" sites, wrapped
form for body sidecars only.* The walkthrough that surfaced this position called out the
cost: the same physical reference (a vault note) needs different envelopes depending on
whether it's reached via a backlink (plain) or via a `[[wiki-link]]` (wrapped). That
duplication paid for nothing — neither population has a field the other can't
legitimately omit. Distinct shapes also reopen "which shape do search results return?"
and "which does a Context Set member store?" — questions that disappear when there is
only one.

---

## 3. The `id` contract across providers

**Decision.** *`id` is opaque to the host, parseable only by its owning provider.* From
the host's point of view, `id` is a stable, equality-comparable string that uniquely
identifies an entity within the scope of `(provider, type)`. The Hypomnema adapter is
the *only* code that knows how to crack a `hypomnema://localhost/vaults/<uuid>/<path>`
URI into its components; the host never parses it, never URL-decodes it, never assumes
a scheme. The same rule applies to `core://`, `github://`, and any future provider's
URI grammar.

**Why.** ADR-010 puts the host ↔ extension boundary on a JSON-RPC wire with capability
negotiation. The host parsing extension-owned URIs would smuggle provider knowledge into
core, exactly the leakage ADR-010 exists to prevent. Treating `id` as opaque keeps the
boundary clean: a new provider declares its URI grammar in its own adapter and the host
adopts the new type without code change. Equality and stable-string semantics are
enough for everything the host needs — dedup, pinning, prompt-cache keys, projection
keys, audit trails.

Two host-side rules fall out of "opaque":

1. **Equality is byte-equality after the provider's canonicalization.** Adapters
   normalize on the way in (e.g. ADR-013's `hypomnema://localhost/...` explicit-authority
   form). Once stored, the host compares ids as strings. If a future provider needs
   semantic equality (case-folding, percent-decoding), it does so *in its adapter*
   before handing the id back to the host.
2. **The host stores ids verbatim.** No re-encoding, no path joining, no scheme
   stripping. Round-tripping through the host is loss-free by construction.

**Ruled out.** *`id` as a structured URI the host may parse.* Would let the host (and
ADR-014 operations, ADR-016 budget planner, ADR-017 capability surface) sniff
`provider://...` segments to short-circuit lookups — but the cost is permanent. Every
new provider URI grammar then becomes a host-level concern; the host has to know which
URI schemes mean what, which segments are tenant-scoped, which carry a vault UUID,
etc. ADR-010's whole point is that extension internals don't leak into the host.

---

## 4. Where does `marker` live?

**Decision.** *`marker` is optional, present only for content-origin references.* A
reference that originated from an inline marker in a content field (a Note body's
`[[wiki-link]]`, a future `@mention` in a chat message, a `#hash` in a tag-bearing
prose field) carries `marker` so the renderer can swap it back into place. A reference
reached through structure — a backlink, a Context Set member, a search result, a
typed `vault` field on a Note — carries no `marker` at all.

**Why.** `marker` answers the question *"what string in the source content do I replace
with the rendered affordance?"* That question only has an answer for content-origin
references. For a structural reference there is no source string — the reference *is*
the field. Making `marker` always-present would force structural sites to invent or
echo a value (the `label`? the `id`? an empty string?), none of which the renderer
can use without re-coupling structure and content.

Two consequences:

- The presence of `marker` is also the discriminator a renderer uses to decide between
  *"draw a citation pill in place of this marker"* and *"draw a card / pill in a list
  view."* The renderer never has to look at where the reference came from in the
  schema; the envelope tells it.
- Resolver behavior is unchanged either way. A content-origin reference goes through
  the same `(provider, type, id)` lookup as a structural one; the marker is carried
  alongside, not used for resolution.

**Ruled out.** *Always-present `marker`.* Easier to spec ("every reference has the same
fields"), worse in practice. Structural references would carry a marker they cannot
populate meaningfully, and renderers would have to special-case "marker is the label"
or "marker is the id" or "marker is empty" — re-inventing the discriminator the
optional-marker form gives for free.

---

## 5. Dangling — universal or Note-specific?

**Decision.** *Dangling is universal.* Any provider may return `resolved: false` on
any reference at any site. For providers that never produce a dangling reference (and
many won't), the cost is one boolean field set to `true` — free. For providers that
*do* — Hypomnema's broken `[[wiki-link]]`, a deleted GitHub repo, a Linear issue moved
to a different workspace, a stale search result whose target was renamed since the
last reindex — having a first-class representation prevents every consumer from
inventing its own "this thing's gone" sentinel.

**Why.** The handoff frames this as "cheap to support everywhere; costs nothing for
providers that never dangle," and that's the entire argument. Three load-bearing
consequences:

1. **Renderers get a single broken-link affordance.** No type-by-type "what does a
   missing target look like" UX work — the schema language already commits to one
   affordance for `resolved: false`.
2. **Prompt serialization gets one rule.** A dangling reference serializes as its
   `label` / `marker` text + an explicit "(unresolved)" hint, never a fabricated body.
   The Context Budget Planner (ADR-016) doesn't have to reserve fetch budget for a
   reference it can already see won't resolve.
3. **Search results get to say "this hit is stale."** A search result whose target has
   been deleted between index and query can ship `resolved: false` instead of either
   lying about the target or omitting the row.

**Ruled out.** *Dangling is Note-specific* (the original placement, in §2's
`resolved_reference` $defs). Would mean every other provider's "the thing's gone"
state needs its own representation: a search result invents a `target_missing` flag,
a Context Set member invents a "broken" status, etc. Each provider re-derives the same
sentinel with slightly different wording, and consumers branch on which provider's
flavor of broken they got. Promoting `resolved` to the universal envelope dissolves
all of that.

---

## 6. Optional partial snapshot

**Decision.** *Optional partial snapshot.* A reference is **canonically just the
pointer** — `{ provider, type, id, resolved }` plus context-origin/marker as needed.
A producer that already has a partial entity in hand (a search result with a title
+ hints, a context-set member with a cached label) **may** include a `snapshot`
field carrying that partial render-time payload. The host **may always ignore the
snapshot and re-fetch.** Snapshots are never required, never authoritative, never
load-bearing for identity or equality.

**Why.** The shape of the snapshot is the ADR-013 presentation hints' input
domain — fields the type's `title` / `summary` / `icon` slots point at, plus the
`hints` object that a custom renderer may want. Two reasons it's worth carrying:

- **Cuts the chatty render path.** A search results page wants to draw 50 cards.
  Without a snapshot, the renderer fires 50 follow-up fetches just to label and
  summarize them. With one, the producer (which already had to read the data to
  decide it was a hit) can include it for free, and the renderer paints the page
  on the first round-trip.
- **Keeps "lean on the wire" intact for the cases that need it.** A Context Set
  serialized for prompt assembly probably *doesn't* want partial snapshots — the
  budget planner is going to make its own degradation decisions (ADR-016).
  Optional means the producer chooses per call.

The host-may-ignore rule is what makes "optional" safe. A snapshot is a render-time
convenience, not a cache. If the host wants the canonical entity (for budget
accounting, for prompt serialization, for citation expansion), it fetches by `id`.
The snapshot never *replaces* a fetch — at best it *defers* one.

**Ruled out (two losers).**

1. *Snapshots are never carried — references are strictly pointers.* Cleanest spec,
   worst render ergonomics. Every list-of-references view (search results,
   suggestion strips, Context Set previews) pays a round-trip per row to do the
   thing the producer could have shipped inline.
2. *Snapshots are a separate handle / sidecar.* Considered: a parallel
   `snapshot_for: <id>` payload alongside the reference, the way the Note body's
   resolution sidecar lives next to the body. Rejected because it duplicates the
   envelope work, forces every consumer that wants the snapshot to thread two
   data structures through together, and re-introduces the "two shapes again"
   problem §2 just dissolved. Co-locating the optional snapshot on the reference
   itself keeps the universal envelope the *only* envelope.

---

## 7. The reserved `expansion` slot — transclusion guardrail

**Decision.** The envelope reserves an `expansion` field with the enum
`pill | summary | full`. v0 honors `pill` only (the renderer / serializer
treats the reference as a citation pill and stops). `summary` and `full` are
reserved values: producers may emit them, but the host's v0 contract is to
**downgrade them to `pill`** rather than honor them. Consumers that depend on
expansion must wait for the transclusion ADR.

This is the load-bearing guardrail. The whole point is preserving the seam.

**Why state it now, in a v0-pill-only envelope?** Two outcomes are possible
when transclusion lands:

- *Additive ADR.* The expansion-policy field exists. The transclusion ADR
  defines what `summary` and `full` mean in concrete terms (depth, budget,
  cycle detection, cache invalidation — see `transclusion-notes.md`), and the
  host's behavior for those values lights up. No wire-format change. No
  migration of existing references stored in Context Sets, citations,
  references arrays, audit logs.
- *Wire-format migration.* The expansion-policy field does *not* exist.
  Transclusion has nowhere to land its per-reference depth/shape hints, so the
  ADR has to extend the envelope. Every stored reference (potentially years of
  durable Context Set membership, citation history, conversation projections)
  has to be migrated or version-gated. Renderers and serializers have to
  branch on envelope version. The cost is permanent and it scales with
  adoption.

Reserving the slot now is the difference between those two outcomes, and the
cost is one optional field that v0 sets to (or interprets as) `pill`.

**v0 host behavior.** When the host sees an envelope with no `expansion`, or
`expansion: "pill"`, the renderer/serializer behaves as it does today. When
the host sees `expansion: "summary"` or `expansion: "full"`, it **downgrades
to `pill`** silently and records a one-line "expansion downgraded — pending
transclusion ADR" diagnostic against the operation log. Nothing fails. This
preserves forward-compatibility: a future Hypomnema build that already speaks
the transclusion vocabulary can safely point at a v0 host.

**Ruled out.** *Skip the field; revisit during the transclusion ADR.* The
exact failure mode this avoids. Skipping turns the transclusion ADR into a
wire-format migration; every consumer of this envelope re-versions. Reserving
costs nothing and keeps transclusion an additive change.

---

## 8. The envelope, formally

```json
{
  "provider": "hypomnema",
  "type":     "hypomnema.note",
  "id":       "hypomnema://localhost/vaults/<uuid>/<path>",
  "resolved": true,
  "marker":   "[[Project Plan]]",
  "label":    "Project Plan",
  "expansion": "pill",
  "snapshot": {
    "title": "Project Plan",
    "hints": { "/* type-specific render hints, ADR-013-shaped */": null }
  }
}
```

### 8.1 Field reference

| Field       | Type    | Required? | Notes |
|---|---|---|---|
| `provider`  | string  | required  | The owning provider id (`hypomnema`, `core`, `github`, …). Routes resolver + renderer dispatch. Pairs with `type`. |
| `type`      | string  | required  | Namespaced type id (`hypomnema.note`, `core.context_set`, `github.repo`). Same dotted form as ADR-013's type envelope. |
| `id`        | string  | required  | **Opaque to the host** (§3). Stable, unique within `(provider, type)`. Equality is byte-equality after the provider's canonicalization. The host never parses, decodes, or splits it. |
| `resolved`  | boolean | required  | `true` for a reference whose target the producer knows exists; `false` for a dangling reference (§5). Universal — every provider sets it. |
| `marker`    | string  | optional  | Present only for **content-origin** references (a `[[wiki-link]]`, an `@mention`, a `#hash`). The renderer swaps the rendered affordance back in for the source string. Absent for structural references (backlinks, Context Set members, search result rows, typed structural fields). §4. |
| `label`     | string  | optional  | A human-readable label the renderer may use when no snapshot is carried and a fetch hasn't happened yet (search-result row title, Context Set list item, broken-link affordance text). Not authoritative for identity; never compared. |
| `expansion` | enum    | optional  | One of `pill | summary | full`. **v0 honors `pill` only**; `summary` / `full` are reserved and downgrade to `pill` (§7). Absent ⇒ `pill`. |
| `snapshot`  | object  | optional  | Partial hydrated entity carried as a render convenience (§6). Shape is the ADR-013 presentation-hints input domain for `type`. The host **may always ignore** and re-fetch by `id`. Never authoritative. |
| `target`    | object  | optional  | Resolved instance sidecar handle (Note body sidecar parity). Present when the producer has already resolved the reference *and* wants to expose the resolved instance through the same envelope (rare outside the body-sidecar site). Carries either a lean ADR-013 instance (`{ type, id, data }`) or a fetchable instance handle. Absent for callers that just want to point at the entity. |

### 8.2 The cross-provider `id` contract, restated

- `id` is a string the host treats as opaque (§3).
- Equality is byte-equality after the owning provider's canonicalization.
- The host stores `id` verbatim; round-tripping is loss-free.
- New provider URI grammars are introduced *in their adapter*, never in the host.
- Provider-internal sub-identity (a vault UUID inside `hypomnema://`, a
  repo path inside `github://`) is the adapter's concern, never the host's.

### 8.3 What this envelope is **not**

- **Not a cache.** `snapshot` is a render-time convenience the host may ignore.
- **Not authoritative for entity content.** The canonical entity is whatever
  fetching by `id` returns through the provider's `serialize-for-prompt` /
  `render` capability (ADR-003).
- **Not transclusion.** `expansion: "pill"` is the only value v0 honors. The
  transclusion ADR consumes the reserved slot; this envelope only preserves it.

---

## 9. Serialized examples — three variants

The same envelope, three populations.

### 9.1 Resolved (the dominant case)

A Note body's resolved `[[ADR-001]]` link, exactly as it lands in `references[]` in
`note-walkthrough.md` §5. Note that the same envelope is what a Context Set member's
`ref`, a search result row's `ref`, a backlink, and a typed `vault` field would all
carry — `marker` and `expansion` are optional, and structural sites omit `marker`.

```json
{
  "provider": "hypomnema",
  "type": "hypomnema.note",
  "id": "hypomnema://localhost/vaults/019dd737-8435-7db3-937a-0884d6b0ce63/adr/adr-001.md",
  "resolved": true,
  "marker": "[[ADR-001]]",
  "label": "ADR-001",
  "expansion": "pill"
}
```

Structural-site variant (a Context Set reference member — no `marker`, `expansion`
omitted):

```json
{
  "provider": "hypomnema",
  "type": "hypomnema.note",
  "id": "hypomnema://localhost/vaults/019dd737-8435-7db3-937a-0884d6b0ce63/Decisions/streaming-protocol.md",
  "resolved": true,
  "label": "Streaming Protocol Decision"
}
```

### 9.2 Dangling (`resolved: false`)

A `[[Mercure vs FrankenPHP]]` wiki-link in a Note body that points nowhere — the
existing Note walkthrough example, expressed in the universal envelope. The renderer
shows a broken-link affordance; prompt serialization emits the `marker` text plus an
"(unresolved)" hint; nothing tries to fetch.

```json
{
  "provider": "hypomnema",
  "type": "hypomnema.note",
  "id": "hypomnema://localhost/vaults/019dd737-8435-7db3-937a-0884d6b0ce63/__unresolved__/Mercure%20vs%20FrankenPHP",
  "resolved": false,
  "marker": "[[Mercure vs FrankenPHP]]",
  "label": "Mercure vs FrankenPHP"
}
```

(The `id` here is the adapter's choice — a Hypomnema convention for "unresolved within
this vault." The host doesn't parse it; the opaque-id contract still holds.)

A dangling structural reference is equally legal — for example, a search-result row whose
target was deleted between indexing and query:

```json
{
  "provider": "github",
  "type": "github.repo",
  "id": "github://repos/acme/foo-archived",
  "resolved": false,
  "label": "acme/foo-archived (deleted)"
}
```

### 9.3 Snapshot-bearing

A search-results page wants to draw a card without firing 50 follow-up fetches. The
producer (which already had to read the entity to decide it was a hit) ships a
`snapshot` with the fields the type's ADR-013 presentation hints will consume. The host
may ignore it and re-fetch — but won't, on the render path.

```json
{
  "provider": "hypomnema",
  "type": "hypomnema.note",
  "id": "hypomnema://localhost/vaults/019dd737-8435-7db3-937a-0884d6b0ce63/Projects/Foo/plan.md",
  "resolved": true,
  "label": "plan",
  "snapshot": {
    "title": "Foo project plan",
    "hints": {
      "summary": "Plan for the Foo launch — milestones, risks, owners.",
      "icon": "file-text",
      "card_fields": {
        "/tags": ["project", "foo", "plan"],
        "/modified": "2026-05-21T18:04:10Z"
      }
    }
  }
}
```

The same envelope, with `expansion` left absent ⇒ `pill`. A future producer running
against a transclusion-aware host can set `expansion: "summary"` or `"full"`; against a
v0 host it downgrades to `pill` silently (§7).

---

## 10. Cross-links and downstream consumers

- **ADR-013 amendment** (`architecture-decisions.md`, ADR-013a): registers the universal
  reference envelope as a first-class concept and the `expansion` policy slot as
  v0-`pill`-only. Points back at this document for the spec.
- **`transclusion-notes.md`**: §1 already names the three sites where transclusion shows
  up (Note body, Context Set member, query-shaped member result). The transclusion ADR,
  when revived, consumes the reserved `expansion` slot — that's the additive vs.
  wire-format-migration distinction §7 makes load-bearing.
- **`search-shape-notes.md`** §7: closes the "pure reference vs. hydrated snapshot"
  open question. Results return references; the optional `snapshot` field is the
  partial-snapshot escape hatch.
- **Step-5 vertical-slice serialization** (`handoffs/handoff-vertical-slice.md`):
  references serialize through this envelope. The Note walkthrough §5 example with
  `resolved_reference` rows is already this envelope; the vertical-slice work just
  consumes it directly.
- **ADR-014 operations** that take a reference as input (context recall, transclusion
  expansion, search, citation rendering) reference this envelope through
  `$defs/reference` in their input schemas, the way the operation-registry walkthrough
  already shows for `core.memory.extract`.
- **ADR-016 Context Budget Planner**: the `expansion: "pill"` default is the budget
  planner's cheapest admission mode; `summary` / `full` (when transclusion ships) are
  the degraded-but-richer modes the planner chooses between.

---

## 11. Decisions and gaps

### Decisions locked this session

- **One universal reference envelope** — `resolved_reference` subsumes the plain triple
  (§2).
- **`id` opaque to the host**, parseable only by its owning provider (§3).
- **`marker` optional, content-origin only** (§4).
- **`resolved: false` is universal** — any provider may dangle (§5).
- **`snapshot` optional, host may always ignore and re-fetch** (§6).
- **`expansion` slot reserved**, v0 honors `pill`-only; `summary` / `full` downgrade
  silently (§7).
- Envelope shape, field reference, three serialized examples (§§8–9).

### Gaps to carry forward

- **Transclusion ADR.** Consumes the `expansion` slot. Until then `summary` and `full`
  are reserved values, not honored. See `transclusion-notes.md`.
- **`snapshot` field shape.** Specified abstractly as "the ADR-013 presentation-hints'
  input domain for `type`." Whether to publish a JSON Schema for the snapshot shape
  per type, or leave it open-objected for v0, is deferred to whichever consumer first
  needs cross-validation (likely the prompt serializer).
- **Dangling-target `id` conventions.** A dangling reference still needs *some* `id`
  string for equality and dedup. v0 leaves the convention to each provider's adapter
  (Hypomnema's `__unresolved__` segment in §9.2 is illustrative, not normative).
  Promote to a host-level convention only if dedup-across-providers becomes important.
- **Cross-provider type overlay.** Open question in `open-questions.md` — if the same
  underlying thing surfaces through two providers (an exported Claude conversation as
  Hypomnema Note *and* a Conversation provider), does it have two `(provider, type, id)`
  tuples or one with overlay metadata? Out of scope here; the envelope is per-provider.
- **`target` sidecar usage outside the Note body.** This envelope carries `target` for
  parity with the Note body sidecar; whether any other site populates it (search? Context
  Set members?) is deferred to those sites' needs.
