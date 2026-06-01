# Streaming Runtime Boundary

> **Status: decided enough for v0 implementation.** ADR-002 and ADR-009 already choose
> assistant-ui's `ExternalStoreRuntime`. This note pins down what that choice buys, what
> still belongs to the host-owned store, and what the backend event protocol must provide.

**Anchored by:** ADR-001/004 (server-authoritative, event-log streaming), ADR-002
(`ExternalStoreRuntime`), ADR-009 (assistant-ui), `design-notes/event-sourced-conversations.md`.

---

## 1. Frame

The frontend does not build a chat runtime from scratch. It builds a small adapter around a
host-owned conversation store and gives assistant-ui a view of that store through
`ExternalStoreRuntime`.

assistant-ui is responsible for chat UI primitives and runtime affordances. The host store is
responsible for ingesting durable stream events, folding them into message projections,
tracking cursors, recovering from missed live delivery, and converting host messages into
assistant-ui message parts.

Iris is the reference negative case for the amount of work avoided at the component/runtime
layer: it implements its own message reducer, stream hooks, composer wiring, message list,
tool-call display, retry controls, and branch/fork actions on top of shadcn. Iris is also the
positive reference for the reconciliation work the host still must do: live events and replay
events arrive in different shapes, reconnect races are real, thread switches happen mid-stream,
and side-channel payloads like artifacts should not be pushed inline with every delta.

## 2. What assistant-ui Decides For Us

Using assistant-ui means the project should not design bespoke equivalents for:

- Thread, message, composer, action-bar, and thread-list component contracts.
- Message editing, reload/regenerate affordances, cancellation affordance, branch switching,
  and tool-result handoff as UI capabilities enabled by runtime callbacks.
- The frontend-facing message-part shape (`ThreadMessageLike` / message content parts) and
  conversion boundary from host message projections into runtime messages.
- Multi-thread runtime integration through an external thread-list adapter.
- Rendering concerns that belong to chat primitives: accessibility, keyboard behavior,
  optimistic composer state, assistant message status display, adjacent assistant-message
  joining, attachment adapter slots, and tool-call grouping.

The implementation consequence: v0 should start from assistant-ui's shadcn components and only
wrap or replace leaf renderers where the product needs typed context affordances, citations, or
artifact previews. It should not fork the core thread/composer/message runtime unless a real
assistant-ui limitation is proven.

## 3. What assistant-ui Leaves To Us

`ExternalStoreRuntime` is intentionally not a streaming protocol. It renders what the external
store gives it and calls handlers the app provides. The host/frontend adapter still owns:

- Server submission: `onNew` turns a composer append into a host command, not a direct model
  call from the browser.
- Live subscription: websocket/SSE/Mercure events are host events first, then folded into the
  external store.
- Replay and resume: the client tracks the last durable sequence per active thread/turn and
  can fetch events after that cursor.
- Event normalization: live-delivery payloads and replay API payloads normalize into one
  internal event union before reducers see them.
- Idempotent folding: duplicate live/replay events must not double-append text, duplicate tool
  calls, or duplicate citations.
- Thread switching while streaming: active-thread state and background streaming state must be
  keyed by thread/turn, not hidden in component-local refs.
- Cancellation: `onCancel` records host cancellation intent; the server pipeline cooperatively
  stops and emits terminal events.
- Branch/retry import: branch history is host state. assistant-ui can display/import branches,
  but the host decides parent ids, branch heads, retry semantics, and which attached context
  carries forward.
- Side channels: artifacts, large tool results, and rich entity payloads are referenced in the
  stream and fetched separately when needed.

The right mental model is: assistant-ui owns the ergonomic runtime surface; the host owns the
conversation state machine.

## 4. Store Shape

The web client should keep one external conversation store, with projections shaped roughly as:

```text
conversationStore
  currentThreadId
  threadsById
  messagesByThreadId          -- host message projection, not assistant-ui's internal state
  runByThreadId               -- status, active turn id, last sequence, cancellation state
  branchRepositoryByThreadId  -- parent ids, branch heads, imported into assistant-ui when needed
  sidePayloadCache            -- artifacts, large tool outputs, entity preview payloads
```

The assistant-ui adapter selects the current thread, converts host messages into
`ThreadMessageLike`, exposes `isRunning` / disabled state, and provides handlers:

- `onNew` -> submit a host turn command.
- `onCancel` -> request host cancellation for the active run.
- `onReload` / `onEdit` -> create a retry/branch command in the host, then stream the new turn.
- `setMessages` / `messageRepository` / import hooks -> bridge assistant-ui branch navigation
  back to host branch state, not to an isolated frontend-only array.
- `onAddToolResult` -> send human/tool-result continuation commands when that feature exists.

For v0, a simple store library is enough. The important constraint is that the store is not
owned by a single `Thread` component instance; reconnect, background streams, thread switches,
and future native clients all depend on the same event/projection contract.

## 5. Backend Event Contract Required By The Store

The frontend adapter needs the ADR-004 event log to expose:

- Monotonic sequence numbers per thread, with a `GET events after cursor` endpoint.
- Thread id, turn id, branch id where relevant, and event id on every stream event.
- Terminal events for completed, failed, and cancelled assistant turns.
- Coalesced content deltas at UI cadence, not raw provider-token granularity.
- Stable ids for message parts, tool calls, citations, and artifacts so replay can be
  idempotent.
- Snapshot-plus-cursor fallback when a client is too far behind to replay cheaply.
- Separate fetch endpoints for large side payloads referenced by stream events.

This is compatible with Mercure, SSE, WebSockets, or a broker. The transport can change; the
client store contract is cursor-based event recovery plus projections.

## 6. Design Consequences

- Do not use assistant-ui's `LocalRuntime` for the primary app. It makes the browser the owner
  of conversation state.
- Do not use the Vercel AI SDK DataStream runtime as the primary contract. It over-specifies
  the backend wire format relative to ADR-004.
- Do not copy Iris's component tree or custom runtime. Use Iris to test reconciliation cases:
  reconnect after hidden tab, missed live event plus replay race, thread switch during stream,
  duplicate tool-call events, cancellation before terminal event, side-payload fetch failure.
- Treat assistant-ui branch support as a UI affordance over host branch state, not as the
  source of truth for branches.
- Keep typed context, citation, and artifact rendering at the leaf/part-renderer layer so the
  core thread runtime remains assistant-ui-shaped.
