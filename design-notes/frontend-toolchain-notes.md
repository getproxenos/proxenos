# Frontend toolchain notes — Node line, Vite vs Vite+

Implementation-time research called for by the Phase 0.0 bootstrap plan ("record Node
version + Vite+ as research-at-implementation items"). Resolves two open items before the
`frontend/` slice is scaffolded. Facts verified 2026-06-13 against primary sources.

The frontend is a decoupled React / assistant-ui SPA (ADR-002, ADR-009). Phase 0.0 scope is
**toolchain + minimal scaffold** proving install → build (into `public/app/`, Vite `base: '/app/'`)
→ served by FrankenPHP in prod, with a Vite dev server (HMR, API proxy) in dev. The real
`ExternalStoreRuntime` ↔ host-store adapter is deferred to the 0.3 streaming contract.

## Node line: Node 24 (current Active LTS)

- `@assistant-ui/react` latest = **0.14.18**. `peerDependencies: react "^18 || ^19"`,
  `react-dom "^18 || ^19"`. There is **no `engines` field** — assistant-ui imposes no Node
  floor of its own; the constraint comes entirely from the bundler.
- **Vite 8** (released 2026-03-12; Rolldown bundler + Oxc transforms by default) requires
  **Node 20.19+ or 22.12+** — unchanged from Vite 7.
- **Decision:** stay on **Node 24** ("Krypton", Active LTS since Oct 2025), which the Nix
  flake already pins (`pkgs.nodejs_24`). It is the latest even-numbered LTS and sits above
  Vite 8's floor. Node 22 LTS is the conservative fallback if a dependency ever regresses.
- React **19** (current stable; within assistant-ui's peer range). assistant-ui is still
  pre-1.0 (0.14.x) — pin exact versions and expect churn; acceptable because the real
  runtime adapter is deferred to 0.3.

## Bundler/toolchain: plain Vite 8 now, Vite+ component tools à la carte

**Vite+** (`voidzero-dev/vite-plus`) is a unified CLI over the VoidZero stack. Status as of
2026-06: **v0.1.24, pre-1.0/beta**, now **MIT / fully open source** (the original
"source-available + paid startup/enterprise tiers" model was dropped — licensing is no
longer a reason to avoid it). It wraps tools that are each adoptable standalone:

| `vite+` subcommand | Underlying tool | Standalone today? |
|---|---|---|
| `vite test` | Vitest | yes — first-class with Vite 8 |
| `vite lint` | Oxlint (Rust, ~100× ESLint, 600+ rules) | yes |
| `vite fmt`  | Oxfmt (targets 99%+ Prettier compat) | yes, but newest/least proven |
| `vite lib`  | tsdown + Rolldown (library bundling) | n/a — we ship an app, not a lib |
| `vite run`  | task runner + monorepo cache | not needed (single SPA) |
| `vite ui`   | devtools | optional |

It bundles **no package manager and no runtime** (so it does not touch our pnpm + Nix
setup), and its deploy target is **Void/Cloudflare Workers** — irrelevant here: we serve
Vite's built static assets through FrankenPHP, not a Workers platform.

**Decision:** scaffold on **plain Vite 8** (stable, MIT, Node 24-supported, minimal surface)
and adopt Vite+'s components individually:

- **Test:** Vitest.
- **Lint:** Oxlint (fast, MIT; the same linter `vite lint` wraps). Add ESLint later only if
  a needed React rule isn't covered.
- **Format:** Prettier now (battle-tested); revisit Oxfmt once it reaches ≥1.0.

Defer the Vite+ **unified CLI** until it ships a stable ≥1.0. Because the engines are
identical (Vite 8, Vitest, Oxc), wrapping them in `vite+` later is a near-free migration —
we get the "opinionated, fast, one-tool" ergonomics without betting the bootstrap on a
pre-1.0 CLI. Re-evaluate when Vite+ tags 1.0.

## Settled

- Node **24** (Active LTS) — already in `flake.nix`; no change.
- React **19**, `@assistant-ui/react` 0.14.x (pinned exact; pre-1.0 churn expected).
- Build tool **Vite 8**; package manager **pnpm** (flake-provided); test **Vitest**;
  lint **Oxlint**; format **Prettier**.
- `frontend/` builds into `public/app/` (Vite `base: '/app/'`) so the SPA sits beside Symfony's
  `public/index.php` without colliding with the `/` route; FrankenPHP serves `/app/*` as static
  files in prod. Vite dev server proxies the API to `app` in dev. Build + lint + test wired into
  the Makefile (`front-*` targets) and CI (a `frontend` job).

## Open / revisit

- **Vite+ ≥1.0**: re-evaluate adopting the unified CLI then (near-free given shared engines).
- **Oxfmt ≥1.0**: swap Prettier → Oxfmt to fully align with the eventual Vite+ direction.
- **assistant-ui 1.0**: the `ExternalStoreRuntime` adapter is 0.3 work against the streaming
  contract (`design-notes/streaming-runtime-notes.md`); expect 0.x API churn until then.
- serversideup runtime UID/GID and the prod asset-copy path are tracked in the bootstrap
  Dockerfile work, not here.
