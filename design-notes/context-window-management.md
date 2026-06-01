# Context Window Management Walkthrough

Worked output of the Iris deeper-dive session on context-window pressure. Iris's
reference design combines a token budget calculator, bounded history loading,
protected recent turns, heavier pruning for older tool output, and async
summarization with a synchronous safety net. This design keeps the operational
shape but adapts it to typed entities, prompt declarations, transclusion, and the
event-sourced conversation model.

This is the prompt-admission counterpart to `design-notes/prompt-declaration-walkthrough.md`:
prompt producers declare what they can render, but the host owns the final budget
plan and decides what enters the model request.

---

## 1. Frame

Long-running threads create pressure from more than chat history. In this system,
the prompt can contain all of these contributors:

- static and dynamic prompt declarations;
- summaries and recent conversation turns;
- tool calls and tool results;
- attached entities and Context Sets;
- transcluded entities and resolved query members;
- retrieved/suggested context;
- images or other media attachments;
- provider tool schemas and response-format schemas;
- reserved completion/headroom tokens.

The design decision is not "summarize old messages eventually." It is that every
model request is built by a **Context Budget Planner** that classifies these
contributors, estimates their token cost, admits them by policy, and records what
was omitted or degraded.

Locked positions:

- **Primitive name:** Context Budget Plan.
- **Planner owner:** the host, not prompt renderers or extensions.
- **Token source:** provider token counts when available; deterministic estimates
  otherwise, marked as estimates in usage accounting.
- **History policy:** recent turns are protected from aggressive pruning; older
  turns degrade through previews and summaries before they are dropped.
- **Entity policy:** attached entities are budget participants with render modes
  (`full`, `summary`, `reference`, `fetchable`), not unconditional prompt text.
- **Compaction:** summarization is async after turns, with a sync safety net when
  history loading would exclude too much unsummarized material.
- **Bounded reads:** every request has load caps for events, turns, summaries, and
  entity expansions so a pathological thread cannot produce unbounded database or
  provider work.

---

## 2. Budget Plan Shape

The planner receives the operation budget from the Operation Registry and produces
a request-scoped plan:

```json
{
  "context_budget_plan_version": "0",
  "operation": "core.chat.respond",
  "context_window": 200000,
  "total_prompt_budget": 140000,
  "reserved": {
    "completion_tokens": 12000,
    "provider_overhead_tokens": 2000,
    "safety_margin_tokens": 6000
  },
  "fixed_costs": {
    "tool_definitions": 8400,
    "response_schema": 1200,
    "static_prompts": 9600
  },
  "allocations": [
    {
      "class": "thread_summary",
      "budget_tokens": 8000,
      "overflow": "drop_oldest_summary"
    },
    {
      "class": "recent_turns",
      "budget_tokens": 45000,
      "overflow": "include_until_budget_exhausted"
    },
    {
      "class": "older_turns",
      "budget_tokens": 22000,
      "overflow": "summary_then_preview_then_drop"
    },
    {
      "class": "attached_entities",
      "budget_tokens": 28000,
      "overflow": "full_then_summary_then_reference"
    },
    {
      "class": "retrieved_context",
      "budget_tokens": 12000,
      "overflow": "ranked_drop"
    }
  ]
}
```

The exact numbers are policy/config, not part of the protocol. The important part
is that the budget is explicit and classed. A renderer that receives 1200 tokens
for `hypomnema.vault_context` cannot accidentally consume the whole request.

---

## 3. Token Accounting

The planner keeps three token categories separate:

- **Measured:** provider-reported prompt/completion/cache tokens from prior calls.
- **Estimated:** local deterministic estimates used before the request is sent.
- **Reserved:** completion, provider overhead, and safety margin.

The v0 estimator can be simple (character-based or tokenizer-backed depending on
provider support), but the record must say whether a number is measured or
estimated. That fits ADR-014's operation usage rows: prompt tokens for
`core.chat.respond` are measured after the provider response, while preflight
budget decisions use estimates.

The effective prompt budget is:

```text
effective_prompt_budget =
  context_window
  - reserved_completion
  - provider_overhead
  - safety_margin
```

Then fixed costs are admitted first: tool definitions, response schemas, and
required static prompts. The remaining budget is allocated to optional or
degradable content classes.

---

## 4. Admission Order

The host admits context in this order:

1. **Required fixed prompts and protocol overhead.** If these do not fit, the
   operation fails before any provider call.
2. **Thread continuity summary.** Recent summaries are compact and should appear
   before raw older turns.
3. **Current user turn and protected recent turns.** These get the least
   aggressive pruning because they define the immediate task and tool state.
4. **Pinned/attached context.** Explicit user intent outranks speculative
   retrieval, but large entities can degrade to summaries or references.
5. **Older turns.** Admit summarized or pruned older turns while budget remains.
6. **Retrieved/suggested context.** Ranked by relevance and dropped first under
   pressure.
7. **Nice-to-have dynamic prompts.** Optional renderers can be omitted with a
   recorded reason.

This ordering is intentionally not "oldest history until budget runs out." It
matches the product model: explicit attachments and recent interaction matter
more than stale raw transcript.

---

## 5. Tool Output Pruning

Tool output is not one homogeneous blob. The host stores full tool calls/results
durably in the event log or projections, then prompt admission renders them in
one of several modes:

| Mode | Typical use | Shape |
|---|---|---|
| `full` | recent protected turns, small results | complete arguments/result |
| `capped` | recent but large result | first N chars/tokens plus truncation notice |
| `preview` | older turns | name, arguments preview, short result preview |
| `placeholder` | old low-value output | tool name/id plus "output omitted" reason |
| `dropped` | budget exhausted | absent from prompt, omission recorded |

Recent protected turns still get per-item caps so one massive shell output or
provider response cannot consume the prompt. Older turns preserve intent before
bulk: tool name, call id, important arguments, and result preview are more useful
than raw bytes.

Tool-call/result reconciliation remains required. If an assistant message has a
tool call whose result is omitted or was interrupted, the rendered history must
include a coherent placeholder rather than creating an invalid provider message
sequence.

---

## 6. Attached Entities

Typed entities are budgeted with explicit render modes:

| Mode | Meaning |
|---|---|
| `full` | serialize the provider's full prompt representation within a cap |
| `summary` | serialize the ADR-013 summary slot or provider summary operation |
| `reference` | serialize only the universal reference, title, type, and reason attached |
| `fetchable` | expose a retrieval tool/handle so the model can ask for more |

Default policy:

- Small, explicitly attached entities can render `full`.
- Large attached entities degrade to `summary`.
- Very large or many attached entities degrade to `reference` plus `fetchable`.
- Query-shaped Context Set members resolve through a separate cap: maximum result
  count, per-result token cap, and total query-member budget.
- Transcluded references default to depth 1 and summary shape unless a site
  explicitly requests a different policy.

The model should be told when degradation happened. A prompt fragment like
"Attached context was summarized due to budget; use `context.fetch` for full
content" is better than silently truncating an entity and pretending it is whole.

---

## 7. Compaction

Compaction has two jobs:

1. Keep long threads usable when raw history no longer fits.
2. Preserve decision/fact continuity better than dropping old turns.

The host runs a summarization operation (`core.conversation.summarize`) after
turn completion when either condition fires:

- **Token pressure:** the last measured prompt tokens exceed
  `context_window * compaction_threshold`.
- **Accumulation:** unsummarized turns exceed `summarization.threshold`.

The summarizer skips a recent buffer so it does not compact the active exchange.
It summarizes the oldest unsummarized eligible segment, links to the previous
summary, and marks the covered turn/event range. Summaries should include at
least:

- user goals and unresolved threads;
- decisions and facts that future turns may need;
- referenced entities and files;
- tool actions that changed state;
- active tasks left in progress.

The summary is not a lossy replacement for storage. Raw turns remain in durable
history. Summary is a prompt-admission artifact and a searchable conversation
projection.

---

## 8. Sync Safety Net

Async compaction can fall behind because workers are down, provider calls fail,
or a user keeps chatting faster than summaries complete. The history loader
therefore reports how many unsummarized turns/events were excluded from the
budgeted prompt.

If excluded unsummarized material crosses a configured threshold, the request
path runs a single synchronous compaction attempt before sending the chat
request. The safety net is deliberately bounded:

- one segment only;
- timeout from the operation declaration;
- advisory lock per thread to avoid duplicate summaries;
- if it fails, continue with degraded context and record the failure.

This prevents an infinite "summarize until clean" stall while still giving the
system a chance to recover when context pressure would otherwise drop important
unsummarized history.

---

## 9. Bounded Loads

Every prompt build uses hard read limits:

- maximum conversation events/turns loaded;
- maximum summaries loaded;
- maximum attached entities expanded;
- maximum query-shaped Context Set results;
- maximum transclusion depth and visited-entity count;
- maximum tool-result bytes/tokens considered before pruning.

These are separate from the model token budget. Token budget prevents oversized
provider requests; load limits prevent oversized database and extension work.

---

## 10. Plan Result

The planner should emit an inspectable result for logs/debug UI:

```json
{
  "estimated_prompt_tokens": 87342,
  "budget_tokens": 140000,
  "entries": [
    {
      "id": "turn:01J0N...",
      "class": "recent_turns",
      "mode": "full",
      "estimated_tokens": 1840
    },
    {
      "id": "entity:hypomnema.note:...",
      "class": "attached_entities",
      "mode": "summary",
      "estimated_tokens": 620,
      "degraded_from": "full",
      "reason": "entity_exceeded_per_item_budget"
    }
  ],
  "omissions": [
    {
      "class": "retrieved_context",
      "count": 7,
      "reason": "ranked_drop_budget_exhausted"
    }
  ],
  "compaction": {
    "attempted_sync": false,
    "excluded_unsummarized_count": 0
  }
}
```

This makes context-window behavior debuggable. Without a plan result, "the model
forgot X" is impossible to distinguish from "X was never admitted."

---

## 11. Gaps Carried Forward

- Exact default token allocations need real prompts and provider limits.
- The provider-specific tokenizer strategy can wait until implementation; the
  protocol only needs measured-vs-estimated accounting now.
- `context.fetch` tool ergonomics need a concrete operation/tool declaration.
- Transclusion still needs its own ADR for depth/shape/caching, but its budget
  hook is now clear: transcluded content is an attached-entity render mode under
  the Context Budget Plan.
