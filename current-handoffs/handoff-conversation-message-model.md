# Handoff — Conversation + Message Model (Phase 0.2)

Build the conversation store. This is **not greenfield design** — `event-sourced-conversations.md`
already specifies the event table, the event vocabulary, and the projection tables, and
ADR-004 already commits the event log as canonical. The job here is to translate that design
into Doctrine and build the event-append + projection-fold machinery, scoped to the subset a
turn loop needs. Where the design is silent on an implementation detail, decide it; do not
re-open the shape.

## Inputs to load

- `design-notes/event-sourced-conversations.md` — the `conversation_events` schema (§2),
  event vocabulary (§3), projections (§4).
- ADR-004 (event log canonical; projections rebuildable).
- 0.1 output (tenant/`workspace_id` scoping).

## Terminology to settle first

- "conversation" (informal) = **thread** (docs). Hierarchy: thread → turn → message →
  message_part. Use these names in code; "conversation" can stay UI copy.

## Decisions to land (implementation, not shape)

1. **Event-sourcing from day one — the load-bearing call.** ADR-004 makes events canonical;
   building projection-rows first and retrofitting an event log later is exactly the
   migration ADR-004 exists to prevent. So even if the only events at first are
   `user_message_submitted` and `assistant_turn_completed`, they are written to
   `conversation_events` and projections are *folded from* them. This decision carries into
   0.3 — flag it loudly.
2. **Projection fold timing.** Synchronous inline fold after each event append (simplest), or
   async via Messenger? *Lean:* synchronous inline for v0; the doc guarantees projections
   are rebuildable, so async fan-out is a later optimization, not a v0 need.
3. **Rebuild command.** Because projections are rebuildable, ship a
   `bin/console app:projections:rebuild <thread>` early — it's cheap now and invaluable the
   first time a projection bug appears.

## Scope — the subset to build now

Build: `conversation_events` (full schema from the doc, including the nullable `branch_id`
column kept unused per the doc), plus the `threads`, `turns`, `messages`, `message_parts`
projections. Event types needed for a text turn:

- `user_message_submitted`
- `assistant_turn_created`
- `assistant_turn_completed`
- (`assistant_content_delta` — define it now even if 0.3 starts non-streaming; one content
  event per turn is fine until streaming lands.)

Defer (columns/types present in the doc, not built yet): `tool_calls`, `citations`,
`artifacts`, connector delivery rows, branch/retry semantics, idempotency keys for connector
submissions. They're in the canonical doc; they're just not in the v0 fold.

## Hard exclusions

- No attached entities, no context grants, no transclusion, no budget accounting.
- No streaming transport wiring here — that's 0.3.

## Downstream

- 0.3 (turn loop) appends `user_message_submitted` and the assistant events, and reads the
  message projection to display the thread.
- Step 5 extends the same event log (the slice's persistence step folds into these tables).
- The eventual "conversation-as-referenceable-entity" work (open question) builds on this
  exact log — another reason to get the event-sourcing decision right now.

## Definition of done

- [ ] `conversation_events` + the four projection tables migrated, `workspace_id`-scoped.
- [ ] Append-event and fold-projection paths implemented for the listed event types.
- [ ] `app:projections:rebuild` reconstructs a thread's projections from its event log.
- [ ] A test that writes a synthetic event sequence and asserts the folded projection.
- [ ] The event-sourcing-from-day-one and fold-timing decisions recorded.
