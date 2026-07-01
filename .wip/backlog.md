# Backlog — cross-cutting

_Unattached ideas / deferrals that don't yet belong to an initiative._

## SPA / chat surface — surfaced during post-phase-0-roadmap step-03

- **Cancellation cache — Redis adapter for multi-worker prod.** D7 (`5db671b`) wired `CacheTurnCancellation` over PSR-6 `cache.app`, which `config/packages/cache.yaml` currently binds to the filesystem pool. Correct for dev / single-process; a multi-worker prod deploy where the cancel request and the streaming turn can land on different PHP processes needs a shared adapter (Redis). Documented inline in `cache.yaml`. Action: swap adapter at the cache-config seam when prod hardening lands; no code change to `CacheTurnCancellation`.
- **Hydration-failure surface (SPA).** D6 (`3fab298`) deliberately falls a hydration failure through to the empty-thread state to avoid an infinite spinner. A distinct "failed to load — retry" affordance is a second-pass UX candidate; `deriveThreadView` already has the seam (the `hydrated` reducer flag can be extended to carry a failure variant). Action: design + ship the failure state alongside the SPA-UX-v2 round.

## Tracked epics (Linear, project `Proxenos` / `BDS`)

The unstarted design work in `design-notes/` and `handoffs/` is now decomposed into Linear
issues. Each source doc carries a **Tracked in Linear** pointer at its top; the epics below are
the entry points. (No round scheduled yet — these are the candidate bodies of work for a future
round or initiative.)

- **[BDS-35 — ADR-010 external-provider boundary + Hypomnema integration](https://linear.app/beausimensen/issue/BDS-35).** The largest cluster, gated on opening ADR-010. Sub-issues BDS-38…44: connector transport (the gate), augmentation pipeline, Vault, Note, memory primitives, skills, vertical slice. Provider-touching issues kept in Proxenos with a "check Hypomnema when we get there" note.
- **[BDS-36 — F2 Operation Registry (ADR-014)](https://linear.app/beausimensen/issue/BDS-36).** Adoption of the registry the Round 1 spine was shaped for. Sub-issues BDS-45…47: registry core, migrate `response.generate`, operation-execution model. Unblocks the ADR-018 render-failure work in BDS-32.
- **[BDS-37 — Context & prompt machinery (beyond shipped v0s)](https://linear.app/beausimensen/issue/BDS-37).** The most host-side / independent cluster. Sub-issues BDS-48…53: full ADR-018 prompt declaration, transclusion expansion, Context Set primitive + membership UX, unified search schema, artifact read/write capabilities.
- **[BDS-30 — Gap Batch Consolidation](https://linear.app/beausimensen/issue/BDS-30).** The small ADR-013 / ADR-018 / `hmn` tag-modality loose ends (BDS-31…34); decomposed from the now-removed `handoffs/handoff-gap-batch.md`.