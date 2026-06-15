# Handoff — SPA Usability v1 (Track D)

Turn the bare assistant-ui Thread from PR #8 into an app you'd actually live in daily —
the near-term Iris replacement for *core* chat use (conversations, threads, the chat
surface, a stop button, a system prompt). This is the payoff of the ADR-026 cutover:
mostly **frontend + thin backend**, touches **no ADR-010**, and runs **parallel to the
`core.document` spine** (Track A). Different code, additive surface.

## Inputs to load

- ADR-002 (`ExternalStoreRuntime`), ADR-009 (assistant-ui primitives), ADR-024 (streaming),
  ADR-026 (the Twig→SPA cutover + the decision rule), `design-notes/streaming-runtime-notes.md`
  (store shape §4 — note the **thread-list adapter** and per-thread run state).
- `frontend/` — the PR #8 adapter (`src/store/`, `src/App.tsx`), Vite config, Vitest setup.
- Backend: `src/Controller/Api/*` (replay, bootstrap, write/cancel), `src/Controller/ChatController.php`
  (the Twig stopgap to retire), `src/Conversation/*` (EventAppender, ProjectionFolder, the
  event vocabulary), `src/Repository/ThreadRepository.php` (`findByTenantOrderedByUpdatedAt`),
  `src/Ai/Chat/ChatRespondLoop.php` (prompt assembly — see the coordination note).
- ADR-025 (the `assistant_turn_cancelled` event is sketched in its open questions),
  ADR-018 (system prompt foreshadowing), ADR-008 (operator-only model selection).
- F1 taxonomy (for the auto-title summarize profile) — **build F1 before Track D**.

## Decisions to land

1. **Build in the SPA, not Twig.** This track is *why* the SPA exists. New surfaces are
   assistant-ui components over the host store; the Twig chat surface is retired at the end.
2. **v1 scope is deliberately the daily-driver minimum** (below). Settings page, edit/branch
   UX, replay snapshot fallback, and auth-UX polish are an explicit **second pass**.
3. **Thread lifecycle is event-sourced** (consistent with ADR-004/022): rename and archive
   are new conversation events folded into the thread projection, not direct table
   mutations. *(Sub-decision to confirm: new `thread_renamed` / `thread_archived` event
   types vs. a generic `thread_metadata_changed` — lean: explicit, named, like the existing
   four-plus-one vocabulary.)*
4. **Cancellation is real backend work**, not just a button: wire the SPA's existing
   `onCancel` stub → the `POST /api/threads/{id}/runs/{turnId}/cancel` stub → a cooperative
   stop in the loop + an `assistant_turn_cancelled` event + a Turn/Message `CANCELLED`
   fold. (Mirror the `assistant_turn_failed` shape from ADR-025.)
5. **System prompt is a simple v0**: a global default (operator/user setting) + an optional
   per-thread override, prepended in prompt assembly. Not ADR-018's full machinery.
6. **Model selection stays operator-only** (ADR-008) — no picker in settings.

## Coordination with Track A (important — shared seam)

Both this track and the spine modify **`ChatRespondLoop`'s prompt assembly**: Track D
prepends a **system prompt**; Track A swaps the dumb history concat for **entity-aware
assembly**. They compose (system prompt → entity context grants → history), but land the
seam carefully so the two workstreams don't clobber each other — agree the assembly
contract (an ordered list of contributions) up front, even though each track fills a
different slot. This is the one place A and D touch.

## Scope — v1 (must-have to daily-drive)

1. **App shell & navigation** — SPA is the home (login → `/app`), client-side routing
   (`/app/threads/:id`), a persistent layout: thread sidebar + active thread + top bar with
   a user menu (email, **sign out** wired to `logout`, tenant indicator).
2. **Thread management** — the assistant-ui **ThreadList** over an external thread-list
   adapter (streaming-runtime-notes §4): list ordered by `updatedAt` (repo method exists),
   new/switch, **rename**, **archive/delete** (events → projection). **Auto-title** a new
   thread from its first message (uses the F1 `task.summarize` profile; a trivial
   first-N-chars heuristic is an acceptable v0 stand-in if you want to ship before F1).
3. **Chat-surface polish** — markdown + syntax-highlighted code blocks (copy buttons),
   message **copy** action, autoscroll with a "jump to latest" affordance, honest
   **FAILED-turn** rendering (from PR #5), empty-thread + loading states.
4. **Stop button** — the cancellation path of decision 4.
5. **System prompt / persona** — global default + per-thread override, injected per
   decision 5; minimal settings affordance to edit it.
6. **Retire the Twig chat stopgap** — once 1–5 cover thread CRUD + chat, delete
   `ChatController`'s Twig actions, `templates/chat/show.html.twig` + its hand-rolled inline
   SSE parser, and the form-POST/SSE-companion routes. *(Keep the Twig login page for now —
   auth-UX polish is second pass.)*

## Hard exclusions (defer to the second pass or other tracks)

- **No edit / regenerate / branch UX** — needs the host branch repository; second pass.
- **No settings page beyond the system-prompt affordance** (theme, account, password —
  second pass).
- **No model picker** (ADR-008).
- **No entity rendering / attach picker / citation pills** — those are Track A's leaf
  renderers; this shell *consumes* them when they land, it doesn't build entity types.
- **No new providers, no ADR-010, no replay snapshot-plus-cursor fallback.**
- **No registration / password reset** (ADR-020).

## Definition of done

- [ ] SPA is the default entry point (login → `/app`), with shell, routing, thread sidebar,
      and a working sign-out.
- [ ] Threads can be listed, created, switched, **renamed**, and **archived** — each via an
      event folded to the projection — and a new thread gets an auto (or heuristic) title.
- [ ] The chat surface renders markdown/code with copy, autoscroll, empty/loading states,
      and **FAILED turns** honestly; streaming still works end-to-end.
- [ ] **Stop** cancels a running turn: cooperative stop + `assistant_turn_cancelled` event +
      `CANCELLED` fold; rebuild reproduces the cancelled state; credential-free test coverage.
- [ ] A **system prompt** (global + per-thread override) is editable and injected into
      assembly without disturbing Phase 0.4/0.5 streaming/failure behavior.
- [ ] The **Twig chat surface is removed**; the no-JS *login* page stays.
- [ ] `make test` + `make front-test` + `make lint` + `make front-lint` green; the
      adapter/reducer reconciliation tests from PR #8 still pass.

## Downstream

- **Track A** entity cards/pills render in this shell via the schema-driven leaf-renderer
  seam (spine step 4).
- **Second pass** — settings page, edit/branch UX + host branch repository, replay snapshot
  fallback, auth-UX polish.
- **`assistant_turn_cancelled`** becomes reusable by connectors (ADR-010) when a transport
  needs to signal host cancellation.
- **Memory / "truths"** (Track B) is what turns "usable" into "actually replaces Iris" —
  schedule it deliberately after the daily-driver shell proves itself.
