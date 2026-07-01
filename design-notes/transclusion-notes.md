# Transclusion — Design-in-Progress Notes

> **Tracked in Linear:** [BDS-49 — Transclusion expansion (summary/full)](https://linear.app/beausimensen/issue/BDS-49) · epic [BDS-37 — context & prompt machinery](https://linear.app/beausimensen/issue/BDS-37).

In-progress design scratchpad, not an ADR. Too thick for a one-line open question, not
settled enough to formalize. This is the largest deferred piece surfaced by the schema-language
walkthroughs.

**Source open question:** "Transclusion design" in `open-questions.md` (Currently chewing on).
**Anchored by:** ADR-013 (schema language / references), ADR-013a (universal reference
envelope + reserved `expansion` slot), ADR-012 (schema-driven rendering),
ADR-004/010 (event log + host↔extension wire protocol).
**Inputs:** `note-walkthrough.md` (body references + resolution sidecar),
`context-set-walkthrough.md` (query-member resolution),
`reference-envelope.md` (universal envelope — reserves the `expansion` slot this design
will populate).

---

## 1. Frame

**Transclusion** is what happens when a referenced entity's content is pulled *into* another
entity's rendered or serialized form, rather than left as a bare link. The schema language
(ADR-013) settled how references are *expressed* — the universal triple, the
`resolved_reference` sidecar, query-shaped members. It deliberately did **not** settle how far
those references *expand* or in what shape. That is this document.

Three concrete sites where transclusion shows up, all already in the design:

- A **Note body** carries `[[wiki-links]]`; `references` resolves each marker to a target
  triple. A renderer (or a prompt serializer) can leave it as a citation pill, or expand the
  target's content inline.
- A **Context Set** member is a reference to another entity; rendering or serializing the set
  means deciding how much of each member to pull in.
- A **query-shaped member** resolves to a *list* of entities — transclusion-of-a-search, with
  the same depth/shape questions multiplied across results.

---

## 2. Open threads

- **Depth limits.** A transcluded entity may itself contain references. How many levels deep
  does expansion go before it stops at a pill? A fixed default (probably 1) with a per-site
  override is the obvious starting point, but unproven.
- **Expansion shape.** At each level, what is rendered/serialized: a **pill** (just the
  `label` + affordance), a **summary** (the `summary` slot from ADR-013 — note the strategy
  form already produces a bounded excerpt), or the **full body**? Likely a per-context choice:
  inline citation → pill; attached top-level entity → summary or full; the renderer and the
  prompt serializer may pick differently. The reference envelope's reserved
  `expansion: pill | summary | full` field (ADR-013a, `reference-envelope.md` §7) is the
  per-reference hint this design will populate; v0 honors `pill` only and downgrades the
  other two, so landing this design is **additive** rather than a wire-format migration.
- **Cycle detection.** Notes backlink each other; A → B → A is normal in a vault. Expansion
  must track visited ids (the canonical URI from ADR-013 is the natural dedup key) and degrade
  a repeat to a pill rather than loop.
- **Prompt-budget integration.** Expansion competes for context window. This is the same
  knob as the "(c) hybrid" size threshold in the *attached entity serialization* open question
  — small entities inline, large ones expand to summary or stay a tool-call-fetchable pill.
  ADR-016 now fixes the budget hook: transcluded content is admitted through the Context
  Budget Planner as an attached-entity render mode. The remaining transclusion question is
  depth/shape/cache policy, not whether expansion participates in the prompt budget.
- **Wire-protocol + pipeline impact.** Does the host pre-expand and ship expanded content, or
  ship lean references (ADR-013) and let the client/serializer expand on demand? Lean-by-default
  fits ADR-012's "schema + data, not pre-rendered content," but the response pipeline needs a
  resolution step with caching (query members are dynamic; see context-set-walkthrough §8).

---

## 3. Gaps carried forward

- Interaction with **query-member resolution** caching/invalidation policy (context-set
  walkthrough explicitly deferred this).
- Whether transclusion is a **renderer concern, a serializer concern, or both** with a shared
  resolution layer underneath.
- Relationship to **citations rendering** (the inline-pill open question under UX edges) — a
  resolved reference and a model-emitted citation may want the same expansion machinery.

## 4. Likely outcome

This earns its own ADR once expansion shape and the prompt-budget knob are exercised against a
real attached-context payload. Until then: lean references on the wire, pill-by-default in the
renderer, summary for top-level attachments, depth 1.
