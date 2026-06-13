# Handoff — Phase 0 Foundation (overview)

Why this phase exists: the step 5 vertical slice presupposes a running host — a booting
Symfony app, a migrated database, a tenancy model, a conversation/message store, and a turn
loop that can call a model. With zero code, none of that exists. Phase 0 builds the base the
slice rides on. The slice itself adds only the *entity → provider → render* half on top.

This is a foundation phase, not a design phase. Most of the architecture is already decided
(see per-session inputs); these sessions mostly resolve a small number of open calls and
then produce build plans / schemas / command signatures to execute in the code environment.

## The four sessions

| # | Session | Shape | Key decision it lands |
|---|---|---|---|
| 0 | Repo + dev environment | build | dev topology, Anthropic client, repo layout |
| 1 | Auth + tenancy model | design + build | resolves the open "auth model" question for v0 |
| 2 | Conversation + message model | reconcile + build | event-sourcing from day one (not rows-first) |
| 3 | Minimal turn loop | build | non-streaming first; no extension boundary yet |

End state of Phase 0: a single console-minted user can hold a multi-turn text conversation
with the assistant, persisted as events, displayed in a minimal web UI. That is the "base to
work from."

## Resequencing against the step 1/3/4/5 plan

Phase 0 inserts *before* step 5, and it pulls one earlier decision forward:

1. **Step 4 · Decision 2 (streaming transport)** — move ahead of everything. It determines
   what the repo scaffolds (Mercure hub in compose vs. FrankenPHP as the app server vs. a
   dedicated service). Decision 1 (Iris replace/coexist) stays where it was; the foundation
   doesn't need it.
2. **Phase 0.0 → 0.1 → 0.2 → 0.3** in order. 0.1 precedes 0.2 because conversation events
   carry `workspace_id`; 0.2 precedes 0.3 because the loop writes to the event store.
3. **Steps 1 and 3 (reference envelope, gap batch) can run in parallel with Phase 0.** They
   are design work and don't touch the foundation; do them in idle design slots while the
   build proceeds. They are prerequisites for step 5, not for Phase 0.
4. **Step 5 (vertical slice)** becomes reachable once Phase 0.3 closes and step 1 has landed.

## Terminology to pin before writing code

The design docs and the informal framing use different words for the same things. Settle
these in session 0.1 and use them everywhere after:

- "account" (informal) ≟ "tenant" / "workspace" (docs, e.g. `core://tenants/personal`).
  Decide whether these are one concept or layered (see 0.1 handoff).
- "conversation" (informal) = "thread" (docs); a thread contains turns, a turn contains
  messages, a message contains parts.

## Hard exclusions for the whole phase (these belong to step 5+)

- No ADR-010 extension boundary, no JSON-RPC, no Hypomnema, no providers.
- No budget planner (ADR-016), no prompt declarations (ADR-018), no entity schema rendering.
- No suggestion engine, no multi-user surface beyond one console-minted user, no branching.
- No artifact write-back. Read/display the conversation; that's it.
