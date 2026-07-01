# Project Primitive (Context Set) — Design-in-Progress Notes

> **Tracked in Linear:** [BDS-51 — Context Set membership & naming UX](https://linear.app/beausimensen/issue/BDS-51) · primitive [BDS-50](https://linear.app/beausimensen/issue/BDS-50) · epic [BDS-37 — context & prompt machinery](https://linear.app/beausimensen/issue/BDS-37).

In-progress design scratchpad, not an ADR. The primitive's name and schema are settled; this
parks the threads the walkthrough left open.

**Settled by:** `context-set-walkthrough.md` and ADR-013.
**Source open question:** none open on naming — recorded here so the resolved name has a home
and the residual threads don't get lost.

---

## 1. What's settled (don't re-litigate)

- **Name: Context Set.** Chosen over "Project" (overloaded by Claude.ai and Linear) and
  "Workspace" (an account/team container). It is a *named set of context references*.
- **Type identity:** `core.context_set`, owned by the host's `core` provider namespace.
- **Schema:** scalar fields (`name`, `description`, timestamps) + an ordered `members[]`
  discriminated union over `reference` and `query` members. See ADR-013 / walkthrough §3.
- **Membership:** ordered by the user in v0. Concrete references use the universal triple;
  query members store a search request + `resolves_to`.
- **Distinction from attached context:** a Context Set is *durable*; the set of context
  attached to a conversation is *transient*. A conversation may attach a Context Set.

## 2. Open threads

- **Relationship to other "Project" concepts.** Claude.ai Projects, Linear Projects, and a
  Context Set can all legitimately appear in the same workspace. A Context Set can even
  *contain* a reference to a Linear project. Is there ever a need to bidirectionally map a
  Context Set onto a provider's native project, or do they stay strictly one-directional
  (Context Set references the native thing, never the reverse)?
- **Concrete vs. query-shaped membership ergonomics.** The data shape is locked; the UX of
  building a set that mixes pinned items and saved searches is not. When does a user reach for
  a query member vs. pinning results individually?
- **Ordering UX.** v0 stores user order; renderers may offer grouped-by-type/provider views.
  Drag-reorder, auto-sort options, and how grouping interacts with stored order are unsettled.
- **Saved searches graduating to entities.** Query members are *not* standalone `Query`
  entities in v0 (ADR-013). If they later need sharing, lifecycle, or attachment outside a
  Context Set, they graduate — without changing the member distinction. Watch for that need.
- **Tag slices remain provisional.** Folder/prefix slices are concrete (`hmn --prefix`); a
  `tag = #project-foo` slice needs a real Hypomnema tag search/filter surface or a declared
  metadata convention before it's anything but aspirational.

## 3. Augmentation

How extensions hang actions/summaries/suggestions off a Context Set is **out of scope here** —
that's `extension-augmentation-notes.md`. The schema deliberately keeps augmentation external.
