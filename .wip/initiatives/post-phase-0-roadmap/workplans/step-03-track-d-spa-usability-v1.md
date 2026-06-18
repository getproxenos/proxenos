# Workplan — step-03 · Track D — SPA usability v1

Turn the bare assistant-ui Thread from PR #8 into a daily-driver chat app: app
shell + nav, thread management (list/rename/archive/auto-title), chat-surface
polish, a real **Stop** button (cooperative cancel + `assistant_turn_cancelled`),
a simple system prompt (global + per-thread override, injected in assembly), and
retire the Twig chat stopgap. Mostly **frontend + thin backend**; touches **no
ADR-010**; runs **parallel to Track A** (step-02, shipped) on different code.

Roadmap entry: `.wip/initiatives/post-phase-0-roadmap/roadmap.md` (Lane D, step-03).
Handoff: `current-handoffs/handoff-spa-usability-v1.md`.
Shared seam: `current-handoffs/handoff-prompt-contribution-contract.md` (the
`PromptContribution` contract step-02 already shipped — Track D fills the
system-prompt slot).

Started: 2026-06-17.

## Decisions (made here, feed later steps)

These are locked. Builders encode them; they do not relitigate.

1. **Build in the SPA, retire Twig at the end.** Every new chat surface is an
   assistant-ui component over the host store (`frontend/src/`). The Twig chat
   surface (`ChatController` actions + `templates/chat/show.html.twig` + the
   form-POST/SSE companion routes) is deleted in the final chunk. The **no-JS
   login page stays** (`SecurityController` + `templates/security/`).

2. **v1 = the daily-driver minimum.** Settings page beyond the system-prompt
   affordance, edit/regenerate/branch UX, replay snapshot fallback, model
   picker, entity rendering, and auth-UX polish are an explicit **second pass**
   (see Hard exclusions). Do not scope-creep into them.

3. **Thread lifecycle is event-sourced** (ADR-004/022), consistent with the
   existing folds. Rename and archive are **new, explicitly-named conversation
   events** folded into the `threads` projection — not direct table mutations,
   not a generic `thread_metadata_changed`. New `ConversationEventType` cases:
   `thread_renamed`, `thread_archived`, `thread_system_prompt_set`. Event-log
   storage is varchar, so adding cases is data-only — **no migration on
   `conversation_events`** (the `threads` projection gains columns; see
   decision 7). Each fold honors the `last_sequence` idempotency cursor every
   projection in this codebase already enforces, so rebuild/replay stay safe.

4. **Cancellation is real backend work.** The flow:
   `onCancel` (already stubbed in `App.tsx`) → `POST /api/threads/{id}/runs/{turnId}/cancel`
   (already a stub in `ApiChatController`) → a **cooperative stop** inside
   `ChatRespondLoop` → a new `assistant_turn_cancelled` event → a Turn/Message
   `CANCELLED` fold. It **mirrors the `assistant_turn_failed` shape** (ADR-025):
   same nullable `message_id`, same turn-then-optional-message fold path. The
   cancel and submit requests are **concurrent HTTP requests**, so the stop
   signal is a **cross-request `TurnCancellation` store** (cache-backed), checked
   by the loop on each coalesced flush — **not** an in-process flag and **not**
   a new `turn_cancel_requested` event on the log (keep the log to terminal
   events only; the requested-marker-as-event alternative is rejected — see
   Open questions).

5. **System prompt is a simple v0** (not ADR-018's full machinery): a **global
   default** (a per-user setting, editable via a minimal affordance) **plus an
   optional per-thread override**. The effective prompt is
   `perThreadOverride ?? globalDefault`; empty/blank yields **no contribution**.
   It is injected as a `PromptContribution(weight: 0, role: system)` ahead of
   entity context and history.

6. **Model selection stays operator-only** (ADR-008). No picker anywhere in the
   SPA. The auto-title summarize model is resolved by profile name, not chosen
   by a user.

7. **The shared `ChatRespondLoop` assembly seam is the step-02 contract,
   unchanged.** `PromptContribution { int $weight, string $role, string $text }`
   (the readonly VO in `src/Ai/Chat/PromptContribution.php`) is **confirmed
   sufficient** for the system-prompt lane — no extra fields for v0. Track D
   ships **system-prompt** contributions at **weight `0`** (define
   `SystemPromptResolver::SYSTEM_PROMPT_WEIGHT = 0`, strictly below
   `PromptAssembler::ENTITY_CONTEXT_WEIGHT = 100`). The loop owns the fold +
   sort; both lanes only return contributions. Track D's loop change is
   **purely additive**: gather system contributions, concatenate with the
   entity contributions the loop already requests, hand the merged list to the
   existing `assemblePrompt()` (which sorts by `weight` ascending). The
   step-02 entity-context path must remain behaviorally identical — a thread
   with no system prompt and no attachments yields the same `MessageBag` as
   today. This is the **one place A and D touch**; the ordered contract is
   `[ systemPrompt(0), entityContext(100), conversationHistory ]`.

8. **The SPA is the default entry point.** `security.yaml`
   `form_login.default_target_path` changes from `me` to the SPA app route
   (`app`). A `SpaController` serves the built `public/app/index.html` for
   `/app` and every client-side sub-route (`/app/threads/{id}`), gated by
   `ROLE_USER` (anonymous → the existing `/login`). Dev still uses the Vite dev
   server (`make front-dev`, proxies `/api`); prod serves the built bundle
   through this controller. The `/me` placeholder page may stay but is no longer
   the post-login target.

9. **Client-minted thread ids.** A "new thread" mints a UUID in the browser
   (`crypto.randomUUID()`) and routes to `/app/threads/{newId}`; **no backend
   call until the first message**, which lazily creates the thread via
   `user_message_submitted` (same lazy-create the loop and projection already
   do). Thread ids are opaque; list ordering is by `updatedAt`, never by id, so
   a v4 client id composes fine with the existing v7 server ids.

10. **Archive is a soft, event-sourced hide — not a hard delete.** `thread_archived`
    sets `threads.status = 'archived'`; the default list endpoint returns only
    `active` threads. No row/event deletion (the log is canonical and must stay
    replayable). "Delete" in the handoff is satisfied by archive for v1.

## Chunks

Each chunk is one focused commit with its own tests; `make test` + `make lint`
(+ `make front-test` + `make front-lint` for frontend chunks) green at each
step. Chunks map to the roadmap's six scope blocks. **Dependency order:** D1 →
{D2 → D3 → D4} for threads; D5/D6 (polish) any time after D1; {D7 → D8} for
cancel; {D9 → D10} for system prompt; **D11 (retire Twig) is strictly last** —
it may only land once D1–D10 cover thread CRUD + chat end-to-end.

### Block 1 — App shell & navigation

**D1. SPA entry, routing, and persistent layout.**
- Backend: `App\Controller\SpaController` — `GET /app/{reactRouting}`
  (`requirements: ['reactRouting' => '.*'], defaults: ['reactRouting' => '']`),
  `#[IsGranted('ROLE_USER')]`, returns `public/app/index.html` (e.g.
  `BinaryFileResponse`); anonymous users hit the firewall → redirect to
  `/login`. Flip `security.yaml` `default_target_path: me` → `app`. Keep
  `^/app` covered by the `main` firewall (it already is via `lazy`/access
  control; add an `^/app … ROLE_USER` access-control rule mirroring `^/me`).
- Frontend: add `react-router-dom`; introduce a layout shell — left **thread
  sidebar**, center **active thread**, **top bar** with a user menu (email +
  tenant name from `bootstrap`, **Sign out** = link/post to `/logout`). Route
  `/app/threads/:id` selects the current thread (`setCurrentThread` + hydrate);
  `/app` with no thread shows an empty-state landing. Lift the existing
  `App.tsx` bootstrap/subscribe/hydrate logic into the shell so it survives
  route changes (subscriptions persist across thread switches — the store is
  already per-thread, handoff §4 case 3).
- Tests: Vitest for the router→currentThread wiring and sign-out target;
  functional PHP test that `/app` is 200 for an authed user and 302→`/login`
  anonymous, and that login redirects to `/app`.

### Block 2 — Thread management

**D2. Backend thread lifecycle: events, folds, service, endpoints.**
- New `ConversationEventType` cases `THREAD_RENAMED`, `THREAD_ARCHIVED`
  (+ `THREAD_SYSTEM_PROMPT_SET` is added in D9, listed here only so the enum
  growth is anticipated). New payloads under
  `src/Conversation/Event/Payload/` (`ThreadRenamed { string $title }`,
  `ThreadArchived {}`), each implementing `EventPayload` like the existing ones.
- `ProjectionFolder`: add `foldThreadRenamed` (→ `Thread::setTitle`, exists) and
  `foldThreadArchived` (→ new `Thread::markArchived()` setting `status =
  'archived'`). Both call `recordEvent()` and respect the `last_sequence`
  guard. Add a `Thread::markArchived()` mutator (mirrors `setTitle`'s minimal
  surface).
- `ThreadLifecycleService` (mirrors `ThreadAttachmentService`): `rename(threadId,
  title)`, `archive(threadId)` — each appends the event via `EventAppender`
  (so it folds **and** fans out over Mercure for live list updates).
- `ThreadRepository`: add `findActiveByTenantOrderedByUpdatedAt(tenantId)`
  (filter `status = 'active'`) alongside the existing method (keep the existing
  one for replay/topic enumeration which must include archived threads' history).
- Endpoints (new `App\Controller\Api\ApiThreadController`, mirroring
  `ApiThreadAttachmentController`'s auth/CSRF/tenancy guards):
  `GET /api/threads` → `[{ id, title, status, updated_at }]` active-only,
  ordered by `updatedAt`; `POST /api/threads/{id}/rename` (`{title}`) → 202 +
  `thread_renamed`; `POST /api/threads/{id}/archive` → 202 + `thread_archived`.
  Reuse the shared `'chat'` CSRF intention and the thread-belongs-to-tenant
  guard.
- Tests: unit for each payload `toArray`; functional fold + rebuild-idempotency
  for rename/archive; functional endpoint happy-path + cross-tenant 403.

**D3. Frontend ThreadList + switching/new + rename/archive UI.**
- Build an external **thread-list adapter** (streaming-runtime-notes §4) over
  `GET /api/threads`; render the assistant-ui **ThreadList** in the sidebar.
- New thread: decision 9 (`crypto.randomUUID()` → route → subscribe lazily;
  it appears in the list after its first message folds and the list re-fetches).
  Switch: route to `/app/threads/:id`, `setCurrentThread` + `hydrateThread`.
- Rename (inline edit → `POST …/rename`) and archive (→ `POST …/archive`,
  optimistic remove then reconcile from the next `GET /api/threads`).
- After a new thread's first turn, or any rename/archive, refresh the list (and
  re-`bootstrap`/subscribe so a brand-new thread gets its Mercure topic — the
  bootstrap enumerates topics per thread; a created-mid-session thread needs a
  fresh subscribe, handoff §3 / `SpaBootstrapController` note).
- Tests: Vitest for adapter mapping, switch-preserves-prior-thread-state, and
  new-thread id flow.

**D4. Auto-title a new thread.**
- `ThreadAutoTitler` service: when a thread is **newly created** by a submit
  (no prior `threads` row before this turn), compute a title and append
  `thread_renamed`. v0 default: **first-N-chars heuristic** of the first user
  message (trim, collapse whitespace, clamp to the 200-char column, ellipsize).
  Make the title source pluggable behind the F1 `proxenos.task.summarize`
  profile resolved by name (`PROXENOS_AUTOTITLE_MODEL_PROFILE`, defaulting to
  the heuristic when F1/the profile is absent — see the F1 lean below).
- Trigger from the submit path (`ApiChatController::submit`), **after**
  `loop->execute(...)`, gated on "thread had no title/row before this turn" so
  it fires once per thread. Keep it out of `ChatRespondLoop` so the core loop
  stays untouched.
- Tests: functional — first message on a fresh thread yields a non-empty title
  via `thread_renamed`; a second message does not re-title; heuristic clamps to
  200 chars.

### Block 3 — Chat-surface polish

**D5. Markdown, code blocks, copy actions, autoscroll.**
- Render assistant/user text as **markdown** with **syntax-highlighted code
  blocks**, each code block with a **copy button**; a per-message **copy**
  action. Use assistant-ui's markdown primitive / a lightweight markdown +
  highlight lib (keep deps minimal; pin to what assistant-ui recommends).
- **Autoscroll** the viewport to the latest delta during streaming, with a
  **"jump to latest"** affordance when the user has scrolled up.
- Tests: Vitest for markdown/code rendering and the scroll-state toggle logic
  (pure parts); the visual/scroll behavior that needs a real browser is flagged
  for Beau's live check (no credential-free DOM-scroll coverage in CI).

**D6. Honest status rendering: empty, loading, FAILED (and CANCELLED).**
- Empty-thread and loading (pre-hydration) states. **FAILED-turn** rendering
  from the reducer's existing `runStatus: 'failed'` + `errorSummary` (PR #5):
  show the partial assistant text plus an honest failure affordance, never a
  silent stall. Wire CANCELLED rendering here once D8 lands its reducer state
  (a cancelled turn shows its partial text marked stopped, not failed).
- Tests: Vitest asserting the failed/empty/loading branches off reducer state.

### Block 4 — Stop button (cooperative cancel)

**D7. Backend cooperative cancellation.**
- New `ConversationEventType::ASSISTANT_TURN_CANCELLED = 'assistant_turn_cancelled'`
  and payload `AssistantTurnCancelled { ?Uuid $messageId, string $finishReason
  = 'cancelled', string $errorSummary = '' }` — **shape mirrors
  `AssistantTurnFailed`**.
- `ProjectionFolder::foldAssistantTurnCancelled` — mirrors `foldAssistantTurnFailed`:
  `Turn::markCancelled()` (TurnStatus::CANCELLED already exists) and, when
  `message_id` present, `Message::markCancelled()`. Add `MessageStatus::CANCELLED`
  + `Message::markCancelled()` + `Turn::markCancelled()` (each mirrors the
  `…Failed` mutators).
- `TurnCancellation` interface: `request(Uuid $turnId)`, `isRequested(Uuid
  $turnId): bool`, `clear(Uuid $turnId)`. Production impl backed by `cache.app`
  (PSR-6/`CacheItemPoolInterface`) with a short TTL; a test double is
  controllable. Register the cache pool (no cache config exists yet — add a
  minimal `config/packages/cache.yaml` using the default app pool, filesystem
  adapter; works in `make test`).
- Wire `ApiChatController::cancel` (currently a no-op stub) to call
  `TurnCancellation::request($turnId)` after the tenancy guard; keep the 202
  `cancel_requested` response.
- `ChatRespondLoop`: in the streaming `foreach`, after each coalesced flush
  (cheap cadence, not per-provider-token), check `TurnCancellation::isRequested($turnId)`;
  on trip, **stop draining the stream**, append `assistant_turn_cancelled`
  (`messageId` = the assistant message if any delta landed, else null — same
  `$deltaCount > 0 ? … : null` logic as the failure path), `clear()` the
  signal, and return a normal `ChatRespondResult` (cancellation is **not** an
  exception — it must not hit the `assistant_turn_failed` catch). The
  `user_message_submitted` event survives (mirrors the failure rationale).
- Tests: unit/functional with a `TurnCancellation` test double that trips after
  the first chunk — assert exactly one `assistant_turn_cancelled` on the log,
  Turn `CANCELLED`, partial Message `CANCELLED`, fewer deltas than the full
  reply, and that `rebuild` reproduces the cancelled state. Functional test of
  the cancel endpoint setting the signal. **Credential-free** (fake Platform +
  controllable signal — decision 4).

**D8. Frontend Stop button + cancelled reconciliation.**
- Add `'assistant_turn_cancelled'` to the `ConversationEventType` union
  (`frontend/src/store/types.ts`) and add `'cancelled'` to `HostMessage.status`
  + a terminal `RunStatus` settle (the store already has the `'cancelling'`
  transient and `markCancelRequested`). Reducer: `applyAssistantTurnCancelled`
  mirrors `applyAssistantTurnFailed` — settle `runStatus`, clear
  `activeTurnId`, mark the partial message `cancelled`.
- Surface the **Stop** button (assistant-ui composer cancel; `onCancel` and the
  `isRunning` wiring already exist in `App.tsx`). On click: `markCancelRequested`
  → `POST …/cancel`; hold `'cancelling'` until the terminal
  `assistant_turn_cancelled` arrives (handoff §4 case 5).
- Tests: Vitest reducer cases — cancel-before-terminal settles on the cancelled
  event; duplicate/late cancelled event folds once.

### Block 5 — System prompt / persona

**D9. Backend system prompt: storage, override event, injection.**
- Global default: a per-user setting. Add `User::$systemPromptDefault`
  (`text`, nullable) + migration, with `GET /api/me/settings` and
  `PUT /api/me/settings` (`{system_prompt_default}`) on a thin controller.
- Per-thread override: `ConversationEventType::THREAD_SYSTEM_PROMPT_SET` +
  payload `ThreadSystemPromptSet { ?string $systemPrompt }` (null clears the
  override); `ProjectionFolder::foldThreadSystemPromptSet` → new
  `Thread::$systemPrompt` (`text`, nullable) column + `Thread::setSystemPrompt()`
  + migration on the `threads` projection (not the event log). Endpoint
  `PUT /api/threads/{id}/system-prompt` (`{system_prompt}`) via
  `ThreadLifecycleService::setSystemPrompt(...)`.
- `SystemPromptResolver`: `forThread(threadId, tenantId, userId): ?PromptContribution`
  → effective = `thread.systemPrompt ?? user.systemPromptDefault`; blank → null.
  When non-null, return `PromptContribution(weight: 0, role:
  PromptContribution::ROLE_SYSTEM, text)`.
- `ChatRespondLoop`: inject `SystemPromptResolver`; in `execute()` build
  `$contributions = array_merge(array_filter([$systemContribution]),
  $entityContributions)` and pass to the existing `assemblePrompt()`
  (decision 7). The resolver needs `userId` — already on `ChatRespondRequest`.
  **Do not** alter the entity-context branch or `assemblePrompt` sort logic.
- Tests: unit for `SystemPromptResolver` precedence (override > default > none);
  functional turn asserting a system segment lands at the front of the
  `MessageBag` ahead of entity context, and that the step-02 entity-only and
  no-contribution paths are byte-identical to before (regression guard).

**D10. Frontend system-prompt affordance.**
- A minimal **settings affordance** (modal/panel off the user menu) editing the
  **global default** (`GET/PUT /api/me/settings`) and a **per-thread override**
  editor (`PUT …/system-prompt`) reachable from the active thread. No broader
  settings page (Hard exclusions).
- Tests: Vitest for the form load/save wiring against mocked transport.

### Block 6 — Retire the Twig chat stopgap

**D11. Delete the Twig chat surface.** (Strictly last.)
- Remove `ChatController`'s Twig actions (`index`, `new`, `show`, `submit`,
  `submitStream`) and the `ChatSubmitInputs` helper, `templates/chat/show.html.twig`
  + its hand-rolled inline SSE parser, and the form-POST/SSE-companion routes.
  Delete the now-dead Twig chat tests (`ChatControllerTest`,
  `ChatStreamControllerTest`) — keep the loop-level tests
  (`ChatRespondLoop*Test`, `EntityAwareTurnTest`, `ProjectionRebuild…`) which
  test the engine, not the Twig surface.
- **Keep** the no-JS login page and `SecurityController`. Confirm `default_target_path`
  is `app` and nothing else routes to `/chat`.
- Tests: `make test` green with the Twig chat tests removed; a functional check
  that `/chat*` routes are gone (404).

## Test strategy

- **Frontend (Vitest, `make front-test`)** — pure reducer/adapter logic:
  thread-list adapter mapping; switch-preserves-prior-thread-state; new-thread
  id flow; `applyAssistantTurnCancelled` + cancel-before-terminal settle;
  failed/empty/loading/cancelled render branches off reducer state; markdown/
  code render + scroll-state toggle (pure parts); settings/override form wiring
  against mocked transport. The existing PR #8 adapter/reducer reconciliation
  tests (cases 1–5) **must keep passing** — every reducer change is additive.
- **Backend unit (PHPUnit)** — new payload `toArray` shapes; `SystemPromptResolver`
  precedence; auto-title heuristic clamping; `TurnCancellation` impl behavior.
- **Backend functional (PHPUnit, fake Platform + `RecordingInMemoryPlatform` /
  `RecordingMercureHub`)** — rename/archive/system-prompt folds **and**
  rebuild-idempotency (`last_sequence`); cooperative-cancel end-to-end with a
  controllable `TurnCancellation` double (one `assistant_turn_cancelled`, Turn
  + partial Message `CANCELLED`, early stop, rebuild reproduces); system-prompt
  injection ordering in the `MessageBag`; endpoint happy-path + cross-tenant
  403; `/app` auth gating; `/chat*` gone after D11. **No live-model coverage
  required** — the Phase 0.5 smoke command already exercises the real bridges.
- **Deferred / flagged for Beau's live check** (no credential-free path):
  EventSource handshake against a real Mercure hub, end-to-end streaming from
  Anthropic into the UI, `mercureAuthorization` cookie attachment, real-browser
  autoscroll/markdown rendering. Note these in each PR's test plan, as PR #8 did.

## Definition of done

- [ ] SPA is the **default entry point** (login → `/app`) with shell, client-side
      routing (`/app/threads/:id`), a thread sidebar, a top bar (email + tenant
      indicator), and a **working sign-out**.
- [ ] Threads can be **listed, created, switched, renamed, and archived** — each
      lifecycle change via an event folded to the `threads` projection — and a
      new thread gets an **auto (or heuristic) title** from its first message.
- [ ] The chat surface renders **markdown/code with copy**, **autoscroll** with
      jump-to-latest, **empty/loading** states, and **FAILED turns honestly**;
      streaming still works end-to-end.
- [ ] **Stop** cancels a running turn: cooperative stop + `assistant_turn_cancelled`
      event + `CANCELLED` fold; **rebuild reproduces the cancelled state**;
      credential-free test coverage.
- [ ] A **system prompt** (global default + per-thread override) is editable and
      injected into assembly at weight `0` **without disturbing Phase 0.4/0.5
      streaming/failure behavior or the step-02 entity-context path**.
- [ ] The **Twig chat surface is removed**; the no-JS **login page stays**.
- [ ] `make test` + `make front-test` + `make lint` + `make front-lint` green;
      the PR #8 adapter/reducer reconciliation tests still pass.

## Open questions to resolve during execution

1. **Where does the global system-prompt default live — `User` column vs a
   settings table vs per-tenant?** _Lean:_ a nullable `text` column on `User`
   with `GET/PUT /api/me/settings`. Per-user is the handoff's "operator/user
   setting" read at its simplest; promote to a settings table only when a
   second setting appears (it won't this step — Hard exclusions).

2. **Cancellation signal: cache-backed store vs an event-sourced
   `turn_cancel_requested` marker?** _Lean:_ cache-backed `TurnCancellation`
   (decision 4). The log stays terminal-events-only; the cooperative check is
   O(1) per flush and credential-free testable via a double. Revisit only if a
   future connector (ADR-010) needs the request itself to be durable/replayable.

3. **Auto-title via F1 `task.summarize` vs first-N-chars heuristic now.**
   _Lean:_ ship the **heuristic** behind a `ThreadAutoTitler` seam that resolves
   `proxenos.task.summarize` by name when present (F1 dependency); the heuristic
   is the documented acceptable v0 stand-in (roadmap "Depends on step-01"). Do
   not block Track D on F1.

4. **Serving the built SPA for client routes — Symfony `SpaController` vs Caddy
   `try_files`.** _Lean:_ `SpaController` catch-all returning
   `public/app/index.html` (decision 8). Keeps `/app` auth-gated in-app and
   testable without Caddy; prod Caddy can still short-circuit static assets.
   Confirm the build output path (`public/app`, base `/app/`) matches the
   controller's file path.

5. **New-thread Mercure subscription mid-session.** `SpaBootstrapController`
   enumerates one subscribe topic per existing thread; a client-minted new
   thread has no topic until re-bootstrap. _Lean:_ re-fetch `/api/me/bootstrap`
   (or just `subscribeThread(newId)` directly, since the JWT's subscribe scope
   is per-thread) after a new thread's first message; for v0 a bootstrap
   re-fetch on `onNew` is acceptable (the SPA already does this per `App.tsx`).
   Verify the minted JWT scope covers a thread created after the token was
   issued — if not, re-bootstrap is mandatory, not optional.

6. **Rename/archive HTTP verb + response.** _Lean:_ `POST` sub-routes
   (`…/rename`, `…/archive`) returning 202 with the new title/status, mirroring
   the existing `ApiChatController` 202 convention and the `'chat'` CSRF
   intention — rather than a REST `PATCH /api/threads/{id}`. Keeps the SPA's
   single CSRF intention and matches the attach/detach controller style.

7. **CANCELLED `RunStatus`/`MessageStatus` terminal value vs reusing
   completed/failed.** _Lean:_ add an explicit `cancelled` to both the frontend
   `HostMessage.status` and `MessageStatus` enum (mirrors how `failed` got its
   own slot) so the UI can render "stopped" distinctly from "errored" — honest
   rendering (decision 4, block 3) depends on the distinction.
