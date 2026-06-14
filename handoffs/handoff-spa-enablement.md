# Handoff — SPA Enablement (between step 1 and step 5)

Pick up the assistant-ui SPA work that Phase 0.3 deferred and Phase 0.4 explicitly
parked. Build the four prerequisites that turn the Phase 0.0 echo scaffold
(`frontend/src/App.tsx`) into a real `ExternalStoreRuntime` over the host-owned
conversation store, then make that SPA the surface the step-5 vertical slice is
designed in. Twig stays as the no-JS fallback the Phase 0.4 streaming work already
preserves; new interactive surfaces start SPA-first from this point on.

This slice sits **between step 1 (reference envelope) and step 5 (vertical slice)**.
Step 1 finalizes the wire shape every reference travels through; step 5 is the
first surface that has to render those references with attach / branch / retry /
streaming UX. Building step 5's UI in Twig would be throwaway — the renderer it
needs is exactly assistant-ui's wheelhouse — so the SPA wakes up first.

## The key insight to state out loud

The SPA-enablement work and the *"streaming hardening (reconnect / replay / resume)"*
that ADR-024 and the Phase 0.3 handoff deferred to *"the assistant-ui SPA work"*
are the **same work**. ADR-024 explicitly parks the cursor / replay / reconnect
endpoint with the line *"belongs to the assistant-ui SPA work (ADR-002/004), not
the Twig stopgap. The event log already supports it; the endpoint can land when
the SPA needs it."* The SPA needs it now. So does the host store
(`design-notes/streaming-runtime-notes.md` §3, §5).

Treat this as one slice, not two — that's the whole point of doing it as a single
handoff rather than two adjacent ones.

## The decision rule the cutover commits to

Document this rule in ADR-026 and apply it from the merge of this slice onward:

- **Read / display, or a simple server-rendered form** → Twig is still fine.
  Login, the workspace settings shell, status pages, the existing form-POST
  thread view as a no-JS fallback — none of these need a runtime.
- **Interactive runtime affordances** → SPA-first. Live streaming beyond a dumb
  reload, cancel, retry / branch, attachments, schema-driven entity cards, the
  picker, citation pills, anything that wants `onCancel` / `onReload` / `onEdit`
  in the assistant-ui sense.

The cutover line is **before step 5**. Step 5 (one Hypomnema Note → resolved by
URI → rendered via schema hints → attached → serialized → streamed back) is the
first feature that crosses into the second bucket, and it does so on every axis.

## Early-warning evidence

PR #4 (Phase 0.4 streaming) shipped ~80 lines of hand-written inline SSE-parsing
JavaScript in a Twig template (`templates/chat/show.html.twig:102-175`) to drive
a single-thread live preview that reloads on `done`. That code reimplements,
poorly, exactly the plumbing assistant-ui's `ExternalStoreRuntime` provides for
free — stream subscription, idempotent rendering, cancellation, terminal-event
handling. Doing step 5's UX in the same shape would multiply that pattern across
attach, branch, retry, citation rendering, and entity cards. Cutting over now
deletes the inline parser instead of growing more of them.

## Inputs to load

- ADR-002 (`ExternalStoreRuntime` as the frontend integration shape), ADR-009
  (assistant-ui as the component library), ADR-023 (Phase 0.3 — explicitly
  defers SPA auth and SPA streaming), ADR-024 (Phase 0.4 — explicitly parks the
  cursor / replay endpoint and Mercure for the SPA), ADR-025
  (`assistant_turn_failed`, so the projection has a terminal state the SPA can
  render).
- `design-notes/streaming-runtime-notes.md` — the canonical statement of what
  assistant-ui leaves to the host (live subscription, replay/resume, event
  normalization, idempotent folding, thread switching, cancellation, branch
  import, side channels) and the backend event contract the store needs.
- `design-notes/frontend-toolchain-notes.md` — the toolchain (Node 24, React 19,
  Vite 8, assistant-ui 0.14.x pinned exact) that the 0.0 scaffold already runs
  on; no changes here.
- `design-notes/event-sourced-conversations.md` — the event log the cursor
  endpoint reads from.
- Phase 0 handoffs already resolved: `handoffs/handoff-gating-decisions.md`
  (Decision 2 — streaming transport, *"prototype Mercure first"*),
  `current-handoffs/handoff-phase0-overview.md` (sets the SPA explicitly aside
  during Phase 0).
- `frontend/src/App.tsx` — the 0.0 scaffold this slice replaces from the inside
  out (same provider tree, real handlers).
- `templates/chat/show.html.twig:95-176` — the hand-rolled SSE parser that
  becomes deletable once the SPA carries the streaming UX.

## The four prerequisites (with interface contracts)

These are the load-bearing pieces. Each one was deferred by a specific ADR; the
deferrals are listed inline so the cross-link is obvious.

### 1. Cursor-based "events after N" replay endpoint

*Deferred by ADR-024, point 4 — "cursor / replay / reconnect endpoint in 0.4".*

The event log already has monotonic `(thread_id, sequence)`; the endpoint just
exposes it. The store needs it for replay-after-reconnect, for catching up after
a hidden-tab resume, and for reconciling live deliveries against durable events
on the same thread.

**Interface contract.**

```
GET /api/threads/{threadId}/events?after={sequence}&limit={N}
  -> 200 application/json
     { events: ConversationEvent[], next_after: sequence | null, has_more: bool }
  -> 401 if unauthenticated, 403 if the thread is not in the caller's tenant.
```

Constraints:

- Events are returned in monotonic `(thread_id, sequence)` order; the client
  treats `sequence` as the cursor.
- The shape of each event is the same shape the live transport emits — same
  envelope, same payload fields. Live + replay normalize through one union
  before `ProjectionFolder` / the store reducer sees them.
- Stable ids on message parts, deltas (per ADR-024's cumulative-replace
  semantics, idempotency is a property of the data), turn-completed,
  turn-failed (ADR-025). The replay reducer is idempotent — replaying the same
  prefix produces the same projection.
- `after=0` is allowed and means "from the beginning"; the snapshot-plus-cursor
  fallback called out in `design-notes/streaming-runtime-notes.md` §5 is **not**
  built in this slice — `limit` plus pagination is enough for v0 thread sizes,
  and the snapshot path can land as an additive endpoint when a thread gets
  large enough to need it.
- Bounded `limit` (server-enforced ceiling, e.g. 500) so a chatty client cannot
  pull the whole log in one shot.

### 2. Push transport — Mercure first, dedicated WS as the fallback

*Deferred by ADR-024, point 4 — "Mercure / broker / WebSocket / Server-side
queue" ruled out for the Twig stopgap, deferred until the SPA needs fan-out and
reconnect."* The gating-decisions handoff already landed the v0 pick: **Mercure
first.** Record the explicit fallback trigger here so the call is reviewable
later.

**Interface contract.**

```
Topic:  /threads/{threadId}/events       # per-thread channel
Auth:   short-lived JWT minted by Symfony, scoped to the threads the
        authenticated user can read in the current tenant.
Frames: same ConversationEvent envelope as the replay endpoint, plus a
        monotonic sequence; the store deduplicates by (thread_id, sequence)
        when a frame races a replay row.
```

Constraints:

- Mercure runs as a Compose service per ADR-011 / the gating-decisions Decision
  2 topology slot. FrankenPHP keeps serving the SPA's static assets out of
  `public/app/` (frontend toolchain notes).
- The host publishes from `ChatRespondLoop` (or its successor) in the same
  place it already calls `EventAppender` — one publish per coalesced delta,
  one per terminal event. No new event vocabulary; the SSE-companion route
  from ADR-024 is replaced by Mercure subscription on the SPA path and
  retained for the Twig fallback until it has no callers.
- **Fallback trigger (record explicitly):** *if replay/resume under reconnect
  cannot be made clean on Mercure — i.e. if the JWT scope model, the
  reconnect-races-replay reconciliation, or the topic fan-out can't be made
  reliable without owning the broker — move to a dedicated WS service
  (Node/Go) per gating-decisions Decision 2.* The trigger is "clean
  resumption under reconnect," not raw throughput or topic count.

### 3. SPA auth wiring

*Deferred by ADR-023, "Ruled out" — "Wiring the React SPA's auth in 0.3."* The
Phase 0.0 scaffold has no auth; the Twig pages use Symfony's `form_login`
session (ADR-020).

Two options the SPA could take; this slice picks one, the ADR records both with
a lean.

| Option | For | Against |
|---|---|---|
| **Reuse the `form_login` session cookie (lean).** SPA fetches go to same-origin endpoints, cookie auto-attaches, CSRF on writes via a meta-tag-supplied token. | Zero new auth surface. Matches ADR-020's "console-minted users, password form login." Same identity story as Twig — easier to keep them aligned during the Twig→SPA migration. Mercure JWT is minted by an authenticated Symfony endpoint, so the cookie still gates everything. | Same-origin only (fine — the SPA ships from `public/app/`). CSRF on every mutating fetch. |
| **Mint a session token (e.g. JWT) for the SPA.** | Cleaner story for a future mobile/native client (ADR-002 keeps that optionality). Decouples SPA lifetime from the browser cookie. | New auth surface, new revocation story, new clock-skew story. Reopens ADR-020's "no token issuance yet" without a v0 reason to. |

**Lean: reuse the `form_login` session cookie.** Same-origin, no new surface,
matches ADR-020. The mobile/native path stays open: when it arrives, add a
token authenticator alongside the session authenticator the same way ADR-020's
open question already anticipates ("multi-user OIDC path … as a second
authenticator").

**Interface contract.**

- The SPA loads from `/app/*` (Vite `base: '/app/'` per frontend-toolchain
  notes). Symfony returns the SPA's `index.html` for unmatched `/app/*` routes
  so client-side routing works.
- The SPA's API base is same-origin `/api/*`. All API routes go through
  Symfony's `form_login` firewall; unauthenticated requests get 401 (JSON),
  which the SPA handles by redirecting to `/login` (server-rendered Twig).
- Mercure JWT is minted by `GET /api/mercure/token` behind the same firewall;
  the SPA refreshes it before expiry.
- CSRF: a `POST /api/csrf/token` (or the same token embedded in the SPA
  bootstrap HTML) supplies a token; mutating endpoints validate it the same
  way the Twig forms do.

### 4. The real ExternalStoreRuntime ↔ host-store adapter

*Deferred by Phase 0.0 / `design-notes/frontend-toolchain-notes.md` —
"`ExternalStoreRuntime` ↔ host-store adapter is deferred to the 0.3 streaming
contract,"* then re-deferred by ADR-023 / ADR-024. This is the part that
replaces the 0.0 echo scaffold in `frontend/src/App.tsx`.

The store shape is already specified in `design-notes/streaming-runtime-notes.md`
§4. This slice instantiates it for v0:

```
conversationStore
  currentThreadId
  threadsById                  -- thread list / titles
  messagesByThreadId           -- host message projection
  runByThreadId                -- status, active turn id, last sequence,
                                  cancellation state
  branchRepositoryByThreadId   -- imported into assistant-ui when branch UI
                                  lights up; v0 may keep this empty
  sidePayloadCache             -- artifacts / large tool outputs / entity
                                  preview payloads (empty for v0)
```

**Interface contract (handler shapes the `ExternalStoreRuntime` provides):**

- `onNew(message)` → `POST /api/threads/{id}/messages` with the composer text;
  the response acknowledges the appended `user_message_submitted`. The live
  stream supplies subsequent deltas. No direct model call from the browser.
- `onCancel()` → `POST /api/threads/{id}/runs/{turnId}/cancel`; the host
  cooperatively stops the loop and appends a terminal event. Cancellation is
  parked by ADR-024's open question; this slice wires the route even if the
  loop-side cooperative cancel lands in a follow-up. The SPA must tolerate
  "cancel requested, no terminal event yet" gracefully.
- `onReload` / `onEdit` → retry / branch is **out of scope for this slice**
  (see hard exclusions); the handler stubs exist and the host endpoints land
  with step 5 or the branching UX. The runtime adapter is shaped so they slot
  in additively.
- `setMessages` / `messageRepository` / import hooks — present at the
  interface level; the v0 implementation just routes them through the host
  branch state (empty for v0). Do not let assistant-ui own branch state.
- `onAddToolResult` — not built; tool-result continuation is a step-5+ concern.

**Reconciliation cases the adapter must handle** (these are the
`design-notes/streaming-runtime-notes.md` §6 "use Iris to test reconciliation"
list — copied here as the test list this slice ships):

1. Reconnect after hidden tab: subscribe to Mercure, replay events after the
   last-seen sequence, dedupe by `(thread_id, sequence)`.
2. Missed live event plus replay race: the same event arriving via both
   transports must fold once.
3. Thread switch during stream: the prior thread's stream stays active in the
   background store; the UI re-renders the newly selected thread.
4. Duplicate cumulative-replace deltas (ADR-024 §1): folding the same delta
   twice converges to the same projection — this is already a property of the
   data; the adapter must not break it by appending instead of replacing.
5. Cancellation before terminal event: the run state shows "cancelling," and
   the terminal event (cancelled / failed / completed) settles it.
6. Side-payload fetch failure: not exercised in v0 (cache empty); the failure
   path is reserved.

## Downstream

- **Step 5 (vertical slice).** Lands on this SPA. The schema-driven entity card,
  the attach picker, the reference pill, the streamed assistant turn, the
  `transclusions` budget-class no-op — all rendered through assistant-ui leaf
  renderers over this store.
- **The Twig stopgap surfaces (`/chat`, `/chat/{threadId}`, the SSE companion
  route).** Stay live as the no-JS fallback ADR-024 already preserves, until a
  follow-up retires them when SPA coverage is complete. The hand-rolled inline
  SSE parser in `templates/chat/show.html.twig` is deletable as soon as the SPA
  is the default entry point.
- **The `assistant_turn_cancelled` event** sketched in ADR-025's open questions
  becomes implementable on top of `onCancel` — the SPA is the first surface
  with a cancel affordance to drive it.
- **The mobile/native optionality** ADR-002 / ADR-010 preserve: this slice
  picks an auth that keeps that door open (cookie for v0, token authenticator
  added alongside when the second client exists), and a store shape that is
  not owned by a single `Thread` component instance.

## Hard exclusions (keep this slice thin)

- **No new entity types, no schema-driven entity rendering, no attach picker,
  no citation pills.** Those are step-5 content. This slice only builds the
  *runtime* that step 5's components plug into.
- **No retry / branch UX.** Handler stubs only; the host endpoints and the
  branch repository land with the UX that needs them.
- **No tool-call rendering, no `onAddToolResult`.** Step-5+ concern.
- **No side-payload (`sidePayloadCache`) population.** Empty for v0; the slot
  is in the store shape so it doesn't have to be retro-fitted.
- **No snapshot-plus-cursor fallback on the replay endpoint.** Pagination is
  enough for v0 thread sizes; the snapshot path is an additive endpoint when
  a thread gets large.
- **No Twig deletion.** The form-POST route, the SSE-companion route, and the
  inline parser stay until SPA coverage is complete and a follow-up retires
  them as a single cleanup.

## Definition of done

The build plan for the future implementation slice. Each checkbox names the
piece of work and the interface-contract section above that pins its shape.

- [ ] **Cursor replay endpoint** (`GET /api/threads/{id}/events?after=&limit=`)
      shipped per §1, including monotonic-sequence ordering, normalized
      envelope identical to the live frame, idempotent fold under replay, and
      tenant/auth gate.
- [ ] **Mercure topology** stood up in Compose per ADR-011 / gating-decisions
      Decision 2, with `/threads/{id}/events` topics, short-lived JWT scoped
      to readable threads, and publish from the same site as `EventAppender`.
      The fallback trigger ("clean resumption under reconnect, else dedicated
      WS service") recorded on the ADR.
- [ ] **SPA auth** via the `form_login` session cookie per §3, with a JSON 401
      contract, a CSRF route the SPA can read, and the Mercure-token endpoint
      behind the same firewall. ADR-026 records the cookie-vs-token decision
      with its lean.
- [ ] **`ExternalStoreRuntime` adapter** replaces `frontend/src/App.tsx`'s 0.0
      echo scaffold per §4, with the store shape from
      `design-notes/streaming-runtime-notes.md` §4, the `onNew` / `onCancel`
      handlers wired, and the six reconciliation cases listed in §4 covered
      by tests (`Vitest`, per frontend-toolchain notes).
- [ ] **The hand-rolled inline SSE parser in
      `templates/chat/show.html.twig`** is identified for deletion in the
      follow-up that flips the default entry point to the SPA (not deleted
      in this slice — the Twig fallback stays live).
- [ ] **`open-questions.md`** updated: the "Streaming approach in the Symfony
      core" item cross-links ADR-026 and this handoff; the SPA-enablement /
      streaming-hardening identity is recorded so the next reader doesn't
      re-derive it.
- [ ] **ADR-026** committed alongside, recording the cutover decision, the
      decision rule, the ruled-out options, and the two open sub-decisions
      (transport, auth) each with a lean.

## What the slice is designed to surface

- Whether the **store / event-contract split** from
  `design-notes/streaming-runtime-notes.md` §4–§5 holds in code or pinches
  somewhere (most likely: the live/replay normalization, or the run-state
  state machine across reconnect).
- Whether **Mercure's clean-resumption story** survives reconnect under load
  — this is the fallback trigger's evidence list.
- Whether the **cookie-based SPA auth** introduces any pinch (CSRF
  ergonomics, 401 redirect handling, Mercure JWT lifetime) the token path
  would have avoided. If it does, ADR-026's open sub-decision flips on
  recorded evidence rather than speculation.
- Whether the four ADR-024 / ADR-023 deferrals were genuinely *one* deferred
  slice (the thesis of this handoff) or quietly two — if the latter, that
  shows up as the replay endpoint and the Mercure work pulling apart in
  scope, which is itself useful signal.
