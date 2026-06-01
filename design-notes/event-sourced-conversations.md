# Event-Sourced Conversations

Follow-up from the Iris session on ADR-004. The question was whether "the event log is canonical" means full event sourcing or merely a replayable wire buffer. The decision here is full event sourcing for conversation state, with optional transport caches layered on top.

## 1. Canonical Means Durable Events

The canonical store is a Postgres-backed `conversation_events` log. Folded tables are read models:

- `threads`, `turns`, `messages`, `message_parts`, `tool_calls`, `citations`, `artifacts`, and delivery rows are projections.
- Search indexes, embeddings, summaries, and "conversation as entity" renderings are projections.
- A projection may be stale, missing, or rebuilt without changing the truth of the thread.

The cursor clients see is the event cursor. A reconnect asks for events after sequence `N`; if the client is far behind, the server may answer with a snapshot plus the next event cursor, but the snapshot is still derived from the event log.

## 2. Event Table Shape

Working schema:

```text
conversation_events
  id uuid primary key
  workspace_id uuid not null
  thread_id uuid not null
  branch_id uuid null
  turn_id uuid null
  sequence bigint not null
  type text not null
  version int not null default 1
  actor_type text not null        -- user, assistant, connector, system, extension
  actor_id text null
  occurred_at timestamptz not null
  correlation_id uuid null
  causation_id uuid null
  idempotency_key text null
  payload jsonb not null
  redaction_state text not null default 'normal'
```

`(thread_id, sequence)` is unique and is the client cursor. `turn_id` scopes assistant-generation events. `branch_id` stays nullable until branching is implemented, but the column keeps retry/branch history from becoming a migration trap. `idempotency_key` is how connector submissions avoid duplicate user turns.

## 3. Event Vocabulary

The first event set should be small and semantic:

- `user_message_submitted`
- `assistant_turn_created`
- `assistant_stream_started`
- `assistant_content_delta`
- `assistant_thinking_delta`
- `tool_call_started`
- `tool_call_arguments_delta`
- `tool_call_completed`
- `tool_call_failed`
- `citation_added`
- `artifact_created`
- `assistant_turn_completed`
- `assistant_turn_failed`
- `turn_cancel_requested`
- `connector_delivery_attempted`
- `connector_delivery_completed`
- `connector_delivery_failed`
- `thread_compacted`

Provider token chunks do not have to be one database row per token. The durable event is the host stream event after host-side coalescing, usually "append this text span" at the same cadence sent to clients. The invariant is that clients and connectors consume the same logical event stream the server persists.

## 4. Projections

The normal UI does not fold from genesis on every request. The host maintains projections:

- Message list projection: role, status, timestamps, parts, citations, artifact refs.
- Turn projection: pending / streaming / completed / failed / cancelled.
- Tool projection: arguments, result metadata, redacted large payload pointers.
- Connector delivery projection: transport ids, ack status, retryability.
- Conversation entity projection: title, summary, promoted decisions, searchable text.

Projection handlers must be idempotent by event id and sequence. Rebuilds can start from periodic snapshots once threads get large.

## 5. Live Delivery

Postgres is enough for v0:

1. Write event in the same transaction as any command-side state.
2. Notify live subscribers with the committed `thread_id` / `sequence`.
3. Clients fetch by cursor from Postgres if they miss a notification.

Redis, Mercure history, or another stream broker may be added for fan-out, mobile reconnect windows, or transport-specific buffering. That buffer is disposable. It is populated from committed Postgres events, may have a TTL, and may be rebuilt or skipped without data loss.

This differs from Iris. Iris writes wire events to a Redis sorted set with a short TTL and folds final state into Postgres at completion. That is a strong reference for the recovery UX, but not the storage model here.

## 6. Cleanup And Archival

Canonical events are not TTL'd. Cleanup has three different meanings:

- **Compaction for prompt/runtime cost:** append `thread_compacted` with a summary and covered sequence range. The raw covered events remain available unless retention policy says otherwise.
- **Cold archival:** old event payloads can move to object storage after projections and manifests are written; Postgres keeps sequence metadata, hashes, event types, and archive pointers so cursors and audits remain coherent.
- **Privacy deletion/redaction:** append a redaction event, scrub sensitive payload fields, and rebuild projections. Redaction is an explicit break from perfect replay, recorded as such.

For the personal v0, keep canonical events indefinitely and postpone cold archival. Design the schema so archival is additive later.

## 7. Consequences

- Conversation-as-entity has a real substrate: render a conversation from events or from a projection that names its source cursor.
- Retry and branching can preserve history instead of overwriting rows.
- Connectors receive host-owned events, not connector-authored state.
- Storage volume is higher than an aggregate-only design; batching deltas and snapshots are the pressure valves.
- Event schema versioning matters from day one because old events outlive code deploys.
