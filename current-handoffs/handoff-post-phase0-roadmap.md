# Handoff — Post-Phase-0 Roadmap

Orientation for everything after Phase 0. Phase 0 built the *stage*; the product —
typed, pluggable context — starts now. This doc names the tracks, the foundational
pieces they share, the gates between them, and a recommended sequence. It is a map,
not a backlog: each named item earns its own handoff when it's picked up.

## Where we are (2026-06-15)

**Shipped + merged (PRs #4–#9):**
- Substrate: auth + tenancy (ADR-020/021), event-sourced conversations + projections
  (ADR-004/022), the streaming turn loop (ADR-024), `assistant_turn_failed` (ADR-025).
- SPA runtime: the four enablement prerequisites (cursor replay endpoint, Mercure
  fan-out, session-cookie auth, the real `ExternalStoreRuntime` adapter) — ADR-026.
  Live-verified.
- Reference-envelope spec — step 1, ADR-013a.
- **Iris = replace** (decided; no provider integration).

**Does NOT exist yet** — the whole typed-context layer:
- The ADR-010 extension boundary is **closed** (no JSON-RPC, no provider host).
- No providers, no `core.document`, no typed entities, no schema-driven rendering.
- No Operation Registry (ADR-014), no budget planner (ADR-016), no prompt
  declarations (ADR-018).

## Two goals, intentionally run in parallel

1. **The vertical spine (Track A)** — prove the typed-context thesis end to end.
2. **A daily-driver-usable app (Track D)** — become a near-term Iris replacement *sooner*,
   on the SPA, without going deep into one feature first (conversations, threads, the
   chat surface, settings/nav).

These touch mostly different code — A is the context layer + a few SPA renderers; D is
the SPA app-shell + thin thread/settings backend — so they parallelize cleanly. Neither
D nor the first half of A needs ADR-010 opened.

---

## Foundational items (cross-cutting; land early, feed every track)

### F1 — Model-profile / task-intent taxonomy  *(its own handoff — see detail below)*
Names the model-selection layer the spine, the operation registry, and every
memory/truth feature consume. Operator-level only (ADR-008 — no user-facing picker).
Small, low-risk, load-bearing. **Do before the spine** so things are named right out
of the gate (cheap to rename later if you'd rather get the spine moving first).

### F2 — Operation Registry seed (ADR-014)  *(optional-early)*
Promote the "operation-compatible" chat loop into a registered `core.chat.respond`, add
a second operation (extraction or summarization). Host-internal; turns the multi-model
orchestration (ADR-008) real. High leverage but a real lift — can land just before B.

### F3 — Gap-batch design amendments (step 3)
The small ADR-013/018 amendments + context-grant enums the walkthroughs deposited. Feeds
Track A's prompt assembly. Fold in when A starts.

---

## Track A — the vertical spine (the product thesis)

The first vertical cut through the context layer: a **typed entity** → **schema-driven
render** (ADR-013) → **reference resolution** (ADR-013a ✓) → **attach/pin** → **prompt
declaration** (ADR-018) + **budget planner** (ADR-016) → **`response.generate`
operation** (ADR-014) → stream (done).

**The load-bearing fork — `core.document`-first vs Hypomnema-first:**
- **`core.document`-first (host-native).** ADR-017 ships a baseline host-storage
  `core.document`. The entire spine can be built and proven against it **without opening
  ADR-010** — no external process, fast feedback — and it gives the SPA its first real
  entity content. *Recommended.*
- **Hypomnema-first (ADR-010 external).** The vertical-slice handoff was written
  specifically to test the ADR-010 process boundary across a real provider — going
  core.document-first **defers that boundary test**. That's the honest tradeoff.

Recommended: build the spine on `core.document` first, **then** open ADR-010 with
**Hypomnema as the first external provider** (the rest of Track A). This serves the
"expand beyond Hypomnema" instinct — the spine isn't hostage to the separate Hypomnema
project being consumable.

## Track B — breadth (mostly post-spine)

- **Operation Registry (ADR-014)** + internal multi-model orchestration (ADR-008) — see F2.
- **A second provider** (exported Claude conversations; or `core.document` alongside
  Hypomnema) — the real test of extensibility-without-forking.
- **Suggestion engine** — lexical + graph first; embeddings later (open-questions).
- **Artifacts / write-back (ADR-017)** — conversations create/update `core.document`s.
- **Connectors (ADR-010 connector primitive)** — inbound from other transports.
- **Memory / "truths" layer** — Iris's signature stickiness; a `core.memory` typed
  entity, spine-adjacent. The single biggest "actually replaces Iris" differentiator —
  schedule deliberately, don't fold into basic chat.
- **Multi-user (team phase, ADR-006)** — deferred until daily personal use proves the
  abstraction.

## Track D — daily-driver usability (parallel to A, built on the SPA)

The payoff of the ADR-026 cutover: flesh the bare assistant-ui Thread into a real app.
Mostly frontend + thin backend; touches no ADR-010. Absorbs the SPA-polish items that
PR #8 deferred.

**v1 slice (must-have to daily-drive):**
- **App shell & nav** — SPA as the home (login → `/app`), client routing, layout
  (thread sidebar + active thread + top bar with user menu / sign out).
- **Thread management** — list ordered by `updatedAt` (repo method exists), new/switch,
  rename + archive/delete (as events → projection), **auto-titled threads** (summarize
  first message; foreshadows the operation registry).
- **Chat-surface polish** — markdown + code blocks (copy), message actions, autoscroll +
  "jump to latest", honest **FAILED-turn** rendering (from PR #5), empty/loading states.
- **Stop button** — the `assistant_turn_cancelled` event (sketched in ADR-025) wired to
  the SPA's existing `onCancel` stub.
- **System prompt / persona** — global default + per-thread override, injected into
  prompt assembly. Simple v0, upgrades into ADR-018 later. High value for "replace Iris".
- → with thread CRUD + chat covered, **retire the Twig stopgap** (delete the hand-rolled
  inline SSE parser + form routes).

**Second pass:** settings page (theme, account), edit/regenerate/**branch** UX + host
branch repository, replay snapshot-plus-cursor fallback for large threads, auth-UX polish.

**Model selection stays operator-only** (ADR-008 / F1) — no user-facing picker.

---

## Dependencies & gates

- **ADR-010 is a one-way door, still closed.** `core.document` lets Track A progress
  without opening it; Hypomnema, external providers, and connectors all wait on it.
- **F1 taxonomy** feeds A's `response.generate`, B's operations, and every memory
  feature — do early.
- **Track D** depends only on the SPA runtime (done) + event-sourced threads (done).
- **Reference envelope** (step 1) is done → serialization in A is unblocked.

## Recommended sequence

0. ~~SPA live verification~~ — done.
1. **F1** — task-intent / model-profile taxonomy handoff (small, foundational).
2. In parallel: **Track A — `core.document` spine**  ‖  **Track D — usability v1**.
3. **Open ADR-010 + Hypomnema** as the first external provider (rest of A).
4. **Track B breadth** (F2 operation registry → suggestions / artifacts / memory) once
   one provider works end-to-end.

## Open decisions / branch points

- `core.document`-first vs Hypomnema-first for the spine (lean: `core.document`).
- Operation Registry (F2) now vs just before B.
- F1 sub-decisions (see below).
- When to retire the Twig stopgap (gated on SPA covering thread CRUD + chat).

---

## F1 detail — for the task-intent / model-profile taxonomy handoff

**Resolved framings (carry these into the session, don't relitigate):**
- Two layers already exist: **profile → `{platform, model, options}`**
  (`proxenos.model_profiles`, built — ADR-023) and **feature → profile** (the ADR-014
  operation's model-profile requirement; lightweight env config now, e.g.
  `PROXENOS_{FEATURE}_MODEL_PROFILE`).
- **Two naming dimensions:** *intent* (`proxenos.task.{chat, code, reason, extract,
  summarize, embed.text}`) and *quality/latency variant* (`.fast` / `.deep` /
  `.frontier`). Today's `chat.frontier` = `task.chat` + `frontier`-quality.
- **v0 = naming convention over one flat map.** Names encode both dims by convention
  (`proxenos.task.reason`, and `proxenos.task.reason.deep` only when actually split);
  bare intent = the default/balanced variant; the resolver stays a dumb one-shot
  `name → profile` lookup. Avoids intent×variant combinatorial explosion.
- **Bespoke profiles are first-class peers.** A feature can bind to any profile name
  (`custom-consolidator`) — no extra mechanism; that's already how name→resolver works.
- **Drop the `xes.model.*` branch** for v0 — bespoke profiles cover "a concrete model
  used in one place"; a model-alias/indirection layer is the deferred 2D machinery.
- Keep **`platform:`** (symfony/ai's term), not `provider:`.
- **Embeddings (`task.embed.text`) is a different capability kind** — resolves to an
  embeddings client (no streaming, carries `EMBEDDING_DIM`); the profile schema likely
  needs a `kind`/capability marker so the resolver routes correctly.

**Open sub-decisions to settle in the session:**
- Final canonical intent list (is `code` in scope for v0, or reserved?).
- Quality variants stay naming-convention-only (recommended) vs become a composed
  `(intent × quality)` resolution axis later.
- Validate/namespace task vs bespoke profiles, or keep the namespace flat.
- The per-profile schema (`platform`, `model`, `options{max_tokens, temperature,
  stream_options}`, `kind`, embedding dim).
- Where feature→profile bindings live: plain config now vs the ADR-014 operation
  declaration later.
- Cross-reference ADR-008 (operator-only), ADR-014 (operation requirement), ADR-023
  (the resolver seam). Likely warrants a new ADR + amendments to those.
