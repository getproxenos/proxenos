# frontend

Decoupled React / assistant-ui SPA for bug-free-happiness (ADR-002, ADR-009).

Phase 0.0 scope is **toolchain + minimal scaffold**: prove install → build → served
by FrankenPHP, plus a Vite dev server with HMR. The real `ExternalStoreRuntime` ↔
host-store adapter is deferred to the 0.3 streaming contract. Toolchain rationale
(Node line, Vite vs Vite+) lives in `design-notes/frontend-toolchain-notes.md`.

## Stack

- **Node 24** (Active LTS) + **pnpm** — both provided by the repo Nix flake.
- **Vite 8** (Rolldown/Oxc) + **@vitejs/plugin-react**, **React 19**.
- **@assistant-ui/react** — the external-store runtime grows into the 0.3 adapter.
- **Vitest** (test), **Oxlint** (lint), **Prettier** (format).

## Commands

Run inside the Nix dev shell (`direnv exec . …`) or from repo root via `make front-*`.

| Task                            | pnpm                                               | make                 |
| ------------------------------- | -------------------------------------------------- | -------------------- |
| Install deps                    | `pnpm install`                                     | `make front-install` |
| Dev server (HMR)                | `pnpm dev`                                         | `make front-dev`     |
| Build into `../public/app`      | `pnpm build`                                       | `make front-build`   |
| Lint + format-check + typecheck | `pnpm lint && pnpm format:check && pnpm typecheck` | `make front-lint`    |
| Test                            | `pnpm test`                                        | `make front-test`    |

## Serving model

- **Dev:** `pnpm dev` serves the SPA with HMR on Vite's port and proxies `/api` to the
  running FrankenPHP `app` container (`make up` publishes :8080).
- **Prod:** `pnpm build` emits hashed assets to `../public/app` with base `/app/`. The
  Docker `frontend` stage runs this build and copies the output into the prod image, where
  FrankenPHP/Caddy serves `/app/*` as static files. `public/app/` is generated — gitignored.
