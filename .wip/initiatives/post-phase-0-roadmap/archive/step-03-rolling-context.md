# Step 3 — Rolling Context (Track D — SPA usability v1)

**Coordinator**: post-phase-0-roadmap-step-03-coordinator (id 550)
**Researcher**: post-phase-0-roadmap-step-03-researcher (id 552)
**Orchestrator**: orchestrator (id 512)
**Workplan target**: .wip/initiatives/post-phase-0-roadmap/workplans/step-03-track-d-spa-usability-v1.md
**Phase**: 1 (workplan production). Build is gated on `build/go/approved` from Orchestrator after human review.

## Tier / spawn posture (LOAD-BEARING)

`selection_reason: human-override`. Per beau's authorization for this Step's lifetime, **every**
agent process (Researcher + all Builders) is spawned with
`mcp__solo__spawn_process(kind="agent", agent_tool_id=3)` (the `Claude` tool). Do NOT run the
strict tier resolver; do NOT edit `.wip.yaml`; do NOT silently pick another tool. Escalation to
`gpt-5.5` (id 17) goes to the Orchestrator first.

- Researcher (id 552): spawned `agent_tool_id=3`, selection_reason: human-override.

## Input-path corrections (bootstrap pointed at wrong dirs)

- **ADRs are NOT in `engineering/decisions/`** (that dir is empty scaffolding, `.gitkeep` only).
  The ADRs (008, 018, 020, 026, plus 002/004/009/022/024/025) live in the consolidated file
  `architecture-decisions.md` at repo root. Also: `.wip/initiatives/post-phase-0-roadmap/decisions/ADR-027b-core-document-spine.md`.
- **step-02's shipped workplan is ARCHIVED**, not in `workplans/`. It's at
  `.wip/initiatives/post-phase-0-roadmap/archive/step-02-track-a-vertical-spine-on-core-document.md`
  (+ `step-02-rolling-context.md` alongside it). This is the entity-aware-assembly half of the
  shared `ChatRespondLoop` contract.
- `design-notes/streaming-runtime-notes.md` exists at repo root (store shape §4, thread-list adapter).

## Shared seam (step-02 ↔ step-03)

`ChatRespondLoop` prompt assembly = ordered list of contributions. Compose order:
system prompt (Track D) → entity context grants (Track A) → history. Track A already landed
its half in step-02. Track D prepends the system prompt slot. Agree/encode the ordered-contributions
contract in the workplan so the two workstreams don't clobber the seam.

## Escalations

(none yet)

## Phase 1 review outcome (Coordinator, 2026-06-17)

Researcher (552) produced the workplan in ~9min and went idle cleanly. **Reviewed: APPROVED, no revision loop.**
- All 4 template sections complete; 11 chunks (D1–D11) across all 6 scope blocks; D11 (Twig retirement) gated strictly last.
- Shared seam encoded (decision 7): `[systemPrompt(0), entityContext(100), history]`, additive over step-02's `PromptContribution {weight,role,text}` — step-02 entity path stays byte-identical. Load-bearing requirement met.
- All 7 Open questions carry a lean.

**Items flagged to Orchestrator for the human's eye (net-new infra the handoff implied but didn't spell out):**
- Cancellation = cross-request **cache-backed `TurnCancellation` store** (decision 4 / OQ2). Adds a minimal `config/packages/cache.yaml` (no cache config exists yet). The one genuinely load-bearing design call.
- System prompt adds a `User.systemPromptDefault` column + migration and a `Thread.systemPrompt` column + migration (D9) — both on projections, not the event log.
- New events `thread_renamed`/`thread_archived`/`thread_system_prompt_set` + `assistant_turn_cancelled` (data-only on the varchar event log; no event-log migration).

Surfaced summary to Orchestrator (512). Researcher (552) kept ALIVE as research sidecar. Build NOT started — awaiting `build/go/approved`.

---

# Step 3 — BUILD (Phase 2)

**Build started**: 2026-06-17. **Approval**: `build/go/approved` from Orchestrator (512), human-reviewed.
**Build branch**: `step-03/track-d-spa-usability-v1` (off `chore/track-release-feature-scaffolding` @ dac2c94). Workplan committed @ `0bd285b`.

## Execution model
Builders share ONE working tree (no Solo worktree isolation) → **strictly sequential**, one Builder per chunk, in dependency order. Each Builder commits ONLY its task-scoped files (never `git add -A` — the working tree carries pre-existing untracked human scaffolding: `agents/`, `commands/`, `.claude-plugin/`, `engineering/`, etc. — DO NOT touch or commit those). Commit convention mirrors step-02: conventional commit + chunk tag, e.g. `feat(spa): ... (step-03 chunk D1)`.

## Batching plan

| Batch | Tasks | Rationale | Dependency |
|---|---|---|---|
| B1 Foundation | D1 | SPA entry/routing/layout — everything sits on it | first |
| B2 Threads | D2 → D3 → D4 | backend lifecycle → ThreadList UI → auto-title | after D1 |
| B3 Polish | D5, D6 | markdown/code/copy/autoscroll; honest empty/loading/FAILED | after D1 (D6 wires CANCELLED after D8) |
| B4 Cancel | D7 → D8 | backend cooperative cancel → frontend Stop+reconcile | after D1 |
| B5 System prompt | D9 → D10 | backend storage/event/injection → frontend affordance | after D1 |
| B6 Retire Twig | D11 | delete Twig chat surface | STRICTLY LAST — only after D1–D10 |

Executed sequentially across all batches (shared working tree). Logical order: D1, D2, D3, D4, D5, D6, D7, D8, D9, D10, D11. D6's CANCELLED-rendering hook depends on D8's reducer state — sequence D6 keeps the FAILED/empty/loading branches and leaves a TODO that D8 satisfies, OR run D6 after D8; lean = run D6 after D8 so cancelled rendering lands whole. **Adjusted order: D1, D2, D3, D4, D5, D7, D8, D6, D9, D10, D11.**

> **Live task status**: query the task ledger by the `post-phase-0-roadmap/step-03` tag, not here.

## Decisions made during build

(append during build)

## Per-task outcomes

(append one outcome paragraph per task completion)

## Ledger-id map (chunk → todo_id)
D1=340 · D2=341 · D3=342 · D4=343 · D5=344 · D6=345 · D7=346 · D8=347 · D9=348 · D10=349 · D11=350

## Builder log
(append per spawn: chunk, builder process id, result, commit SHA)

### D1 — ✅ PASS (Builder 01, pid 555) → commit `21b7440`
SpaController catch-all + `security.yaml default_target_path me→app` + `^/app ROLE_USER`; App.tsx refactored to persistent shell (bootstrap/subscribe/hydrate lifted, route-driven selection), react-router-dom@7, shell components (TopBar/ThreadSidebar placeholder/ThreadRoute/ThreadSurface/EmptyState). Gates all green: make test 135/610, make lint clean, front-test 17, front-lint clean, front-build clean. 17 files, task-scoped. Closed builder 555.
**Carry-forward soft flags (in effect for downstream builders):**
- `symfony/mime` NOT installed → SpaController sets `Content-Type: text/html` explicitly. Any chunk wanting mime guessing must `composer require symfony/mime`.
- **For D3:** bootstrap no longer auto-selects first thread — selection is route-driven (`ThreadRoute`); `/app` = empty state. D3's list/new-thread flow builds on route-driven `selectThread` (= setCurrentThread + subscribe + hydrate). Bootstrap still subscribes to all `subscribed_topics`.
- OQ4 RESOLVED: Vite `base:'/app/'` + `outDir:../public/app` matches controller path; `public/app` is gitignored (not committed).

### D2 — ✅ PASS (Builder 02, pid 556) → commit `1557598`
ConversationEventType THREAD_RENAMED/THREAD_ARCHIVED + ThreadRenamed/ThreadArchived payloads; ProjectionFolder foldThreadRenamed/foldThreadArchived (ensureThread→last_sequence guard→recordEvent); Thread::markArchived; ThreadLifecycleService (rename/archive via EventAppender); ThreadRepository::findActiveByTenantOrderedByUpdatedAt (kept the existing method); ApiThreadController. Gates green: make test 147/649 (+12), make lint clean. 11 files, task-scoped. Closed builder 556.
**Carry-forward soft flags (D3 MUST consume these):**
- Endpoint contracts: `GET /api/threads` → items `{id, title (nullable), status, updated_at (ATOM)}` active-only/tenant-scoped; `POST /api/threads/{id}/rename {title}` → **202** `{status:"thread_renamed"}`; `POST /api/threads/{id}/archive` → **202** `{status:"thread_archived"}`. Shared `'chat'` CSRF intention (ApiControllerSupport).
- Rename rejects `title` > 200 chars → **400** `validation_failed` (projection col is varchar(200)); blank title → 400. D4 auto-titler owns its own clamp.
- Non-existent (well-formed UUID, not in projection) on rename/archive is NOT 404 — event appends + ensureThread materializes (mirrors ApiThreadAttachmentController). Cross-tenant = 403.
- Payload convention is `type()` + `toArray()` only; no `fromArray` on EventPayload (folds reconstruct inline from `$event->getPayload()`).
- **Test-cache gotcha (all later builders):** after adding a new wired service/controller, `make test` may 500 with "controller/service does not exist in the container" until `APP_ENV=test php bin/console cache:clear`. Not committed; clear test cache if you hit it.

### D3 — ✅ PASS (Builder 03, pid 557) → commit `983f5f9`
Frontend ThreadList: pure external adapter (`store/threadList.ts`: mapThreadListResponse/threadDisplayTitle/removeThread/newThreadId) + transport (fetchThreadList/renameThread/archiveThread, shared 'chat' CSRF) + ThreadSidebar (switch/New/inline-rename/archive, route-driven) + App.tsx (threads state, refreshThreadList, reBootstrap, optimistic rename/archive). Gates green: front-test 34 (+17), front-lint clean, front-build OK. 8 files, task-scoped. Closed builder 557.
**OQ5 RESOLVED:** subscribe JWT enumerates one topic per existing thread, NO wildcard → client-minted mid-session thread is NOT in original token scope → **re-bootstrap mandatory** for new threads (mint fresh JWT, resubscribe, replay to backfill). submit runs synchronously so onNew reconcile is deterministic. Existing threads skip reBootstrap.

#### Decision made during build (D3) — CUSTOM ThreadList, not assistant-ui ThreadListPrimitive
Builder deviated from the handoff's literal "assistant-ui ThreadList" wording: rendered a **custom sidebar list** over the pure external adapter + react-router, NOT `ThreadListPrimitive`/`ExternalStoreThreadListAdapter`.
**Reason (sound):** assistant-ui's thread-list runtime owns selection state + its own new-thread lifecycle (id on first message), which conflicts with (a) D1's route-as-source-of-truth selection mandate and (b) decision 9's client-minted-id-BEFORE-first-message. Forcing the primitive = two competing selection authorities.
**Intent satisfied:** external adapter over GET /api/threads ✓, switch/new/rename/archive ✓, route-driven selection ✓. ADR-009 tension noted (still uses assistant-ui for the chat surface; only the thread-LIST went custom).
**Coordinator assessment:** accept; not blocking; blast radius contained to D3 (downstream builds on composer/rendering, not on ThreadList-as-primitive). **Surfaced to Orchestrator/human for veto-window** before more UI stacks. If human wants the primitive → it's a D3 revisit (builder-03-r1); D4 backend + adapter-based reconcile survive either way.

**D4 carry-forward:** titles nullable end-to-end → "Untitled thread" fallback until D4 sets one; list reconciles post-onNew + via reBootstrap so a fresh `thread_renamed` auto-title surfaces on next GET /api/threads. No assistant-ui ThreadList runtime to integrate against.

### D4 — ✅ PASS (Builder 04, pid 558) → commit `98b3751`
ThreadAutoTitler + ThreadTitleSource interface + HeuristicThreadTitleSource (trim/collapse/clamp-200/ellipsize); triggered from ApiChatController::submit AFTER loop->execute, gated once-per-thread (captured isNewlyTitleable BEFORE loop), appends thread_renamed via D2's ThreadLifecycleService::rename (SYSTEM actor, no new event). Source pluggable behind PROXENOS_AUTOTITLE_MODEL_PROFILE via ModelProfileResolver → heuristic fallback (F1 confirmed absent). Gates green: make test 155/673 (+8), make lint clean. 7 files, task-scoped. Closed builder 558. OQ3 + Decision 6 honored.
**Carry-forward (LOAD-BEARING for any builder adding a controller/constructor arg):** the test-cache gotcha is real and `cache:clear` is INSUFFICIENT — a stale compiled controller factory keeps the old constructor arity → ArgumentCountError 500 cascading into unrelated functional tests. **Fix: `rm -rf var/cache/test && APP_ENV=test php bin/console cache:warmup`** (full wipe). Relevant to D7 (ChatRespondLoop/ApiChatController cancel wiring) and D9 (system-prompt resolver injection into ApiChatController/loop).
**D3 deviation status:** no veto from Orchestrator/Beau as of D4 advance → custom-ThreadList stands.

### D3 deviation — RATIFIED by Beau (via Orchestrator, post-D4)
Custom sidebar over assistant-ui ThreadListPrimitive is **accepted as-shipped** (`983f5f9`). Route-driven-selection + client-minted-id rationale sound; primitive's selection-state conflicts with URL-as-source-of-truth. **No rework, no ADR amendment** (rationale in shared note + commit context is adequate). Veto window on D3's shape is CLOSED — D5+ UI may stack on the custom sidebar freely.

### D5 — ✅ PASS (Builder 05, pid 559) → commit `65ef841`
Markdown (react-markdown@10 + remark-gfm@4 + rehype-highlight@7) as MessagePrimitive Text slot; code blocks w/ per-block copy (childrenToText copies raw source); per-message copy via ActionBarPrimitive.Copy (native); autoscroll via ThreadPrimitive.Viewport native autoScroll + custom JumpToLatest (passive listener, pure shouldShowJumpToLatest). Gates green: front-test 54 (+20), front-lint clean, front-build OK. 10 files, task-scoped. Closed builder 559.
**Note:** false-positive idle-fire mid-build required one status-check nudge; verified commit landed on re-check before advancing.
**Soft flag:** react-markdown used directly (not @assistant-ui/react-markdown) — same custom-over-primitive precedent as D3 (ratified); chat surface stays on assistant-ui per ADR-009 (Viewport/Composer/MessagePrimitive/ActionBar native).
**Deps added:** react-markdown@10.1.0, remark-gfm@4.0.1, rehype-highlight@7.0.2. No hljs theme file (inline .hljs-* theme in index.css).
**Beau live-check list (D5):** autoscroll stickiness during streaming; jump-to-latest show/hide in real browser; clipboard writes (secure-context navigator.clipboard + execCommand fallback); syntax-highlight legibility light/dark + copy-button hover + code overflow.

### D7 — ✅ PASS (Builder 06, pid 560) → commit `5db671b` [LOAD-BEARING — verified carefully]
ASSISTANT_TURN_CANCELLED event + AssistantTurnCancelled payload (mirrors AssistantTurnFailed); MessageStatus::CANCELLED + Turn/Message::markCancelled; foldAssistantTurnCancelled (last_sequence guard); TurnCancellation interface + CacheTurnCancellation (PSR-6 cache.app, 300s TTL); config/packages/cache.yaml (new); ApiChatController::cancel wired → request(turnId); ChatRespondLoop checks isRequested per coalesced flush → break → append cancelled event from NORMAL return (not the failed catch) → clear → normal ChatRespondResult; user_message_submitted survives. Gates green: make test 165/710 (+10), make lint clean. 18 files, task-scoped. Heeded D4 full-cache-wipe. Closed builder 560.
**Contract VERIFIED in tests:** exactly one assistant_turn_cancelled, no completed/failed, fewer deltas (1<3), Turn+partial Message CANCELLED, rebuild reproduces, cancellation NOT routed through failed catch. ControllableTurnCancellation double (services_test.yaml). ✓ all decision-4 / OQ2 requirements met.
**Carry-forward → D8:** wire payload keys are `{message_id, finish_reason:'cancelled', error_summary}`; terminal status value = `'cancelled'`. D8's applyAssistantTurnCancelled mirrors applyAssistantTurnFailed reading these.
**⚠️ Coordinator-only flag for STEP-SHIPPED / Beau awareness (prod limitation, non-blocking v1):** CacheTurnCancellation uses the filesystem cache.app pool — fine single-process/dev, but a multi-worker prod deploy where the cancel request and the streaming turn land on DIFFERENT processes needs a shared adapter (Redis). Documented inline in cache.yaml. This is the OQ2 cross-request store's one real caveat — surface it in the step-shipped note.

### D8 — ✅ PASS (Builder 07, pid 561) → commit `cfa8d08`
'assistant_turn_cancelled' added to ConversationEventType union + 'cancelled' to HostMessage.status + 'cancelled' terminal RunStatus settle; reducer applyAssistantTurnCancelled mirrors applyAssistantTurnFailed (settles runStatus→'cancelled', clears activeTurnId, marks partial message 'cancelled', reads {message_id, finish_reason, error_summary}); ThreadSurface composer swaps Send⇄Stop via ThreadPrimitive.If running + ComposerPrimitive.Cancel. App.tsx needed NO change (onCancel/markCancelRequested/requestCancel/isRunning wiring already existed pre-D8). Gates green: front-test 57 (+3, PR#8 cases 1-5 still pass), front-lint clean, front-build OK. 5 files, task-scoped. Closed builder 561. Block 4 (Stop) COMPLETE.
**Carry-forward → D6 (LOAD-BEARING for correct rendering):** applyAssistantTurnCancelled deliberately does NOT set errorSummary (cancel ≠ error; D7 emits error_summary:''). So D6 renders CANCELLED as: runStatus==='cancelled' AND partial message status==='cancelled' → show "stopped" w/ preserved partial text, NOT via the errorSummary failure banner. The 'cancelling' transient = Stop pressed/awaiting terminal → D6 may show a "Stopping…" hint there.

### D6 — ✅ PASS (Builder 08, pid 562) → commit `3fab298`
Pure deriveThreadView(thread)→ThreadView module (loading|empty|{ready,banner: none|failed|cancelled|cancelling}); added `hydrated:boolean` to ThreadState + markHydrated (NOT in fold path → PR#8 reference-stability intact); ThreadStatus.tsx presentational (ThreadPlaceholder + ThreadStatusBanner); ThreadSurface takes `view` prop; App.tsx markHydrated in hydrateThread finally + passes deriveThreadView. FAILED → role=alert + errorSummary; CANCELLED → neutral "You stopped this response." role=status (NOT failure banner, per D8 flag); 'cancelling' → "Stopping…" aria-busy. Gates green: front-test 76 (+19), front-lint clean, front-build OK, PR#8 cases 1-5 pass. 9 files, task-scoped. Closed builder 562. **Block 3 (polish) + Block 4 (cancel) COMPLETE.**
**Soft flag (coordinator-only, possible backlog):** hydrateThread marks `hydrated` in finally → on replay FAILURE too, so a thread whose cursor-replay fails (no live events) shows EMPTY rather than a distinct "failed to load" surface. Deliberate (avoids infinite-spinner stall D6 forbids; errorSummary is turn-failure not load-failure). A dedicated hydration-failure affordance is out-of-D6-scope — candidate for second-pass backlog.
**D6 Beau live-check:** banner placement in scrolling viewport; FAILED colour legibility light/dark + stopped-vs-error visual distinction; loading→empty→ready transition feel; "Stopping…" hint during real cancelling window.

### D9 — ✅ PASS (Builder 09, pid 563) → commit `8d81622` [SHARED SEAM — regression guard VERIFIED]
User.system_prompt_default + Thread.system_prompt (both text nullable, migration Version20260617000000); THREAD_SYSTEM_PROMPT_SET event + ThreadSystemPromptSet payload + foldThreadSystemPromptSet (last_sequence guard); ThreadLifecycleService::setSystemPrompt (extends D2); SystemPromptResolver::forThread → PromptContribution(weight 0, role system), blank→null, override>default>none; ApiThreadController PUT .../system-prompt (202); ApiMeSettingsController GET/PUT /api/me/settings (direct users write, not event-sourced — User=identity per ADR-020). ChatRespondLoop: array_merge(array_filter([systemContribution]), entityContributions) → existing assemblePrompt() — **entity branch + sort UNTOUCHED**. Gates green: make test 184/769 (+19), make lint clean. Full-cache-wipe applied. 16 files, task-scoped. Closed builder 563.
**✅ REGRESSION GUARD CONFIRMED:** testNoSystemPromptNoEntityYieldsByteIdenticalUserOnlyBag passes; EntityAwareTurnTest + all ChatRespondLoop* tests pass UNCHANGED. Ordering proven: [SystemMessage(prompt), SystemMessage(entity), UserMessage]. Decision 7 ordered contract [systemPrompt(0), entityContext(100), history] holds. **The A↔D shared seam composed cleanly — no clobber of step-02.**
**Soft flags:** resolveEffective() is public static (pure precedence core, unit-tested directly since repos are final); /api/me/settings is a direct projection write (OQ1 lean — User column not settings table).
**Carry-forward → D10:** endpoints `GET/PUT /api/me/settings {system_prompt_default}` + `PUT /api/threads/{id}/system-prompt {system_prompt}` (202, null/blank clears), shared 'chat' CSRF intention.

### D10 — ✅ PASS (Builder 10, pid 564) → commit `c0a1e3b`
transport fetchMeSettings/saveMeSettings/saveThreadSystemPrompt (shared 'chat' CSRF, accepts 200+202); 'thread_system_prompt_set' added to FE event union + applyThreadSystemPromptSet fold (ThreadState.systemPrompt, reads wire key system_prompt, null clears — touches no message/run state); SystemPromptDialog (one reusable modal, DI load/save seam); TopBar "Settings" (global default) + ThreadSurface "System prompt" toolbar btn (per-thread override). Gates green: front-test 87, front-lint clean, front-build OK, PR#8 + all prior pass. 10 files, task-scoped. Closed builder 564. **Block 5 (system prompt) COMPLETE.**
**Soft flag:** per-thread override editor LOADS via the reducer fold (no GET endpoint — backend untouched per D9); reads currentThread.systemPrompt from folded thread_system_prompt_set events (cursor replay + live Mercure carry it). Additive + idempotent.
**D10 Beau live-check:** override value reflecting after Save depends on the live Mercure event (fold unit-tested; EventSource handshake not CI-coverable); modal visual/UX (backdrop, Escape, dark-mode).

**STATUS: 10/11 done. Only D11 (retire Twig, STRICTLY LAST) remains, then step boundary.**

### D11 — ✅ PASS (Builder 11, pid 565) → commit `b8e49dc` [FINAL — ADR-026 cutover complete]
Deleted ChatController.php (all-Twig: index/new/show/submit/submitStream + ChatSubmitInputs + chat_* routes), templates/chat/show.html.twig (inline SSE parser), ChatControllerTest + ChatStreamControllerTest. KEPT (verified): ApiChatController (SPA backend), SpaController, SecurityController + no-JS login + templates/security/, all loop-level tests (ChatRespondLoop*/EntityAwareTurnTest/ProjectionRebuild*/SystemPromptInjectionTest). /chat*→404 confirmed (router:match none; debug:router shows only api_chat_submit/cancel + app). security.yaml default_target_path=app ✓. Gates ALL green: make test 180/730, make lint clean, make front-test 87, make front-lint clean. Closed builder 565.
Soft flag (cosmetic, coordinator-only): a stale prose docblock in the KEPT ThreadEventsControllerTest mentions "ChatStreamControllerTest" — left untouched to avoid editing an engine test; no code dependency.

---

## STEP SHIPPED — all 11 chunks landed (2026-06-18)

Branch `step-03/track-d-spa-usability-v1` = kickoff `0bd285b` + 11 chunk commits on `dac2c94`:
D1 21b7440 · D2 1557598 · D3 983f5f9 · D4 98b3751 · D5 65ef841 · D7 5db671b · D8 cfa8d08 · D6 3fab298 · D9 8d81622 · D10 c0a1e3b · D11 b8e49dc

### DoD verification (all 7 ✓)
1. SPA default entry + shell/routing/sidebar/sign-out → D1 ✓
2. Threads list/create/switch/rename/archive (event-folded) + auto-title → D2/D3/D4 ✓
3. markdown/code+copy/autoscroll/empty/loading/FAILED honest → D5/D6 ✓
4. Stop cancels: assistant_turn_cancelled + CANCELLED fold + rebuild reproduces + credential-free → D7/D8 ✓ (contract verified)
5. system prompt global+per-thread editable + injected at weight 0 WITHOUT disturbing streaming/failure OR step-02 entity path → D9/D10 ✓ (byte-identical regression guard passed)
6. Twig chat removed, no-JS login stays → D11 ✓
7. make test + front-test + lint + front-lint green; PR#8 reconciliation tests pass → ✓ (final: 180 backend + 87 frontend green)

### Carry-forward caveats for Beau (NOT blocking the merge)
1. **D7 prod hardening:** CacheTurnCancellation uses the filesystem cache pool — multi-worker prod where cancel + stream hit different PHP processes needs a shared adapter (Redis). Documented inline in cache.yaml. (Backlog candidate.)
2. **D6 hydration-failure:** a thread whose cursor-replay fails (no live events) shows the EMPTY state, not a distinct "failed to load" surface — deliberate (avoids infinite spinner); a dedicated affordance is second-pass. (Backlog candidate.)
3. **D3 custom ThreadList:** ratified by Beau — custom sidebar over assistant-ui ThreadListPrimitive (route-driven selection + client-minted ids). No rework.
Plus: live-browser checks accumulated across D5/D6/D8/D10 (autoscroll, clipboard, Mercure handshake, modal UX) — flagged per-chunk for PR-time verification (no credential-free CI path, same as PR#8).

**Run posture:** 11 chunks, 11 Builders (one per chunk, agent_tool_id=3 human-override throughout), zero retries, zero escalations, one false-positive idle (D5) handled with a nudge. Sequential on a shared working tree; every commit task-scoped (human scaffolding never touched).
