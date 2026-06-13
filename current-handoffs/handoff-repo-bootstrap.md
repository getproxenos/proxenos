# Handoff — Repo + Dev Environment Bootstrap (Phase 0.0)

Stand up the empty-but-running host: a Symfony app that boots, a migrated database, a dev
environment matching the deployment target, CI, a test harness, and the model-client
abstraction (`symfony/ai`) the turn loop will use later. No domain logic yet — the goal is a
green `docker compose up` and a passing test run.

## Prerequisite

**Step 4 · Decision 2 (streaming transport) must be made first.** It determines whether the
compose file includes a Mercure hub, runs FrankenPHP as the app server, or fronts a
dedicated streaming service. Don't scaffold transport-shaped infra until it's decided.

## Inputs to load

- ADR-007 (PHP/Symfony + Doctrine, CTI for typed entities).
- ADR-011 (Docker Compose → Coolify → Hetzner; air-gapped Compose for team later).
- ADR-008 (provider/model is a backend concern; no user-facing model picker) and ADR-014
  (model profiles: `chat.frontier`, `reasoning.medium`, `reasoning.fast`, `embedding.text`).
- The streaming-transport decision from step 4.
- `symfony/ai` docs (the Platform component / AI Bundle) — the chosen model abstraction.

## Decisions to land

1. **Repo layout.** Single host repo for now. Extensions are separate processes (ADR-010)
   and get their own repos when they exist — don't pre-build a monorepo for absent
   extensions. Record this so it's a deliberate choice, not a default.
2. **Model client = `symfony/ai`.** Decided: adopt `symfony/ai` (the Platform component,
   wired via the AI Bundle) as the model abstraction rather than rolling a client or using
   Prism (Prism is Laravel-tied; it's what Iris uses, not portable here). The Platform's
   `PlatformInterface` + provider bridges give vendor-neutral provider/model switching by
   config, which is exactly the ADR-008/ADR-014 shape. Sub-decisions to land:
   - **Which provider bridge(s) to configure first.** Late-bound via config, so this isn't a
     lock-in — but pick a v0 default. *Lean:* an OpenAI-compatible bridge as the portable
     default (the lingua franca most hosted and local backends speak), with Anthropic
     configurable alongside it behind the same interface. A local bridge (e.g. Ollama) is
     worth wiring too, for key-free dev.
   - **Scope: Platform only.** Use `symfony/ai` for the *model call*. Do **not** adopt its
     Chat component for conversation history — that collides with the event-sourced model
     (0.2). Its Store (vector/RAG) and Agent (tools/workflows) components are out of Phase 0
     scope; revisit Store when in-core embeddings land and Agent against the ADR-014
     operation registry later.
   - **Version pinning.** `symfony/ai` is experimental (no BC promise) and pre-1.0; pin a
     known-good version and treat upgrades as deliberate.
3. **Postgres image.** Provision with `pgvector` available now even though Phase 0 doesn't
   use embeddings — it's a known future need (in-core embeddings, ADR-016 neighborhood) and
   baking it into the dev image now avoids a later infra change. Cheap insurance.
4. **Secrets handling.** How provider API key(s) reach the app in dev (`.env.local`, not
   committed) and the shape that will map to Coolify secrets in deploy. Keyed per provider
   bridge so adding a provider later is config, not plumbing.

## What this session produces

- Symfony skeleton booting in a container.
- `compose.yaml` for dev: app, Postgres (+pgvector), and whatever the transport decision
  requires (Mercure hub / FrankenPHP config / none).
- Doctrine + Doctrine Migrations wired; an empty initial migration runs clean.
- Symfony Messenger configured (async transport present, even if unused in Phase 0 —
  compaction and operations will need it).
- PHPUnit harness with one trivial passing test.
- GitHub Actions CI: install, migrate against a throwaway Postgres, run tests.
- `symfony/ai` installed and configured (AI Bundle), with at least one provider bridge
  reachable and one integration-style smoke test (guarded so it doesn't run in CI without a
  key). A key-free local bridge (Ollama) makes the smoke test runnable offline if wired.

## Downstream

- 0.1 (auth/tenancy) adds the first real migrations on this skeleton.
- 0.3 (turn loop) consumes the `symfony/ai` Platform and the transport, behind the
  model-profile resolver it introduces.

## Definition of done

- [ ] `docker compose up` yields a booting app + healthy Postgres (+ transport infra if any).
- [ ] `bin/console doctrine:migrations:migrate` runs clean on an empty schema.
- [ ] CI is green on a trivial test.
- [ ] A configured `symfony/ai` provider bridge returns a completion in a local smoke test.
- [ ] Repo-layout, model-client (symfony/ai + first bridge), pgvector, and secrets decisions
      recorded. The model-client choice is substantive enough to deserve its own ADR (e.g.
      ADR-019: model abstraction = `symfony/ai` Platform) rather than a `docs/` note —
      `symfony/ai` / Prism aren't yet captured anywhere in the artifact set.
