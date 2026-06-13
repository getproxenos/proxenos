# Handoff — Universal Reference Envelope (step 1)

Formalize the canonical cross-provider reference shape so transclusion, query-shaped
references, search results, and serialization all build on one vocabulary instead of three
near-duplicates. This is the keystone of the remaining design work — do it first.

## Scope

Produce a written reference-envelope spec, a serialized example covering every variant
(resolved / dangling / snapshot), and an ADR-013 amendment stub registering the reference
as a first-class concept. Out of scope: transclusion expansion mechanics (deferred — see
the guardrail in §4), and any per-provider URI grammar beyond what identity requires.

## Inputs to load

- `design-notes/note-walkthrough.md` — §2 reference triple, §5 `resolved_reference` sidecar,
  §6 "the reference triple → direct input to handoff-2".
- `design-notes/context-set-walkthrough.md` — `reference` members already reuse the triple;
  `query` members resolve to references, not bodies.
- `design-notes/search-shape-notes.md` — the result `ref`, and the open
  "pure reference vs. hydrated snapshot" question.
- ADR-003 (typed entities / graph walks), ADR-013 (envelope language),
  ADR-010 (extension boundary + the universal-reference bridge).

## The questions to answer

1. **One shape or two?** Is the canonical reference the plain triple
   `{ provider, type, id }`, or the enriched `resolved_reference`
   (`{ ...triple, marker, resolved, target? }`)? Decide whether one subsumes the other.
2. **`id` contract across providers.** Hypomnema uses vault-scoped `hypomnema://` URIs;
   core uses `core://…`. Is `id` an opaque provider-owned string, or a structured URI the
   host is allowed to parse?
3. **Where does `marker` live?** Always present, or only for references that originate from
   inline content (a `[[wiki-link]]`)?
4. **Is `resolved: false` (dangling) universal** — any provider may return an unresolvable
   reference — or Note-specific?
5. **Optional hydrated snapshot.** Can a reference carry a partial entity snapshot so a
   renderer can draw a card without a follow-up fetch? Decide: never / optional-partial /
   separate handle.

## Recommended positions (overridable)

- **Enriched-as-universal.** `resolved_reference` is the universal shape; the plain triple
  is its degenerate, always-resolved case (`resolved: true`, no `marker`). One type, two
  populations.
- **`id` opaque to the host, parseable by its owning provider.** The host treats it as a
  stable string; only the Hypomnema adapter knows how to crack a `hypomnema://` URI. Keeps
  ADR-010's boundary clean.
- **`marker` optional**, present only for content-origin references.
- **Dangling is universal.** Cheap to support everywhere; costs nothing for providers that
  never dangle.
- **Optional partial snapshot**, but the reference is *canonically* just the pointer — the
  snapshot is a render-time convenience the host may ignore and re-fetch.

## Transclusion guardrail (do not skip)

Transclusion is being deferred as a session but its seam stays open here. The envelope
**must reserve an expansion-policy slot** — `pill | summary | full` — even though v0 only
honors `pill`/reference. This is the difference between reviving transclusion as an
additive ADR later versus a wire-format migration. Note it explicitly in the spec.

## Downstream handoffs

- Transclusion ADR (when revived) — consumes the expansion-policy slot.
- Context Set `query` member resolution output — uses this as its result-row reference.
- The vertical slice (step 5) serialization — references serialize through this shape.
- ADR-013 amendment — registers the reference type and the expansion-policy slot.

## Definition of done

- [ ] Reference-envelope spec written (the five questions answered, with ruled-out options).
- [ ] Serialized example showing resolved, dangling, and snapshot-bearing variants.
- [ ] Expansion-policy slot present and documented as v0-`pill`-only.
- [ ] ADR-013 amendment stub drafted.
- [ ] `open-questions.md` updated: note-walkthrough handoff-2 reference question marked resolved.
