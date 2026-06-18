# Backlog — cross-cutting

_Unattached ideas / deferrals that don't yet belong to an initiative._

## SPA / chat surface — surfaced during post-phase-0-roadmap step-03

- **Cancellation cache — Redis adapter for multi-worker prod.** D7 (`5db671b`) wired `CacheTurnCancellation` over PSR-6 `cache.app`, which `config/packages/cache.yaml` currently binds to the filesystem pool. Correct for dev / single-process; a multi-worker prod deploy where the cancel request and the streaming turn can land on different PHP processes needs a shared adapter (Redis). Documented inline in `cache.yaml`. Action: swap adapter at the cache-config seam when prod hardening lands; no code change to `CacheTurnCancellation`.
- **Hydration-failure surface (SPA).** D6 (`3fab298`) deliberately falls a hydration failure through to the empty-thread state to avoid an infinite spinner. A distinct "failed to load — retry" affordance is a second-pass UX candidate; `deriveThreadView` already has the seam (the `hydrated` reducer flag can be extended to carry a failure variant). Action: design + ship the failure state alongside the SPA-UX-v2 round.