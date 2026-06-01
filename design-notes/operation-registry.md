# Operation Registry Walkthrough

Worked output of the Iris deeper-dive session on the "14 operations" pattern.
Iris uses a light convention: config keys, service classes, queued jobs, and
`TokenUsageSource` names. This design keeps the useful naming discipline but
makes registration explicit enough for ADR-010's extension boundary.

This is the operation-primitive analogue of the entity and prompt declaration
work: operations are declared once by the host or an extension, then invoked by
the response pipeline through lean request envelopes.

---

## 1. Frame

An operation is a named unit of model-adjacent work: primary chat response,
memory extraction, context recall query generation, summarization, thread
naming, embedding generation, suggestion ranking, or a future extension-owned
pipeline step.

Locked positions:

- **Primitive name:** Operation Declaration.
- **Registry name:** Operation Registry.
- **Identifier:** dotted provider-owned id, e.g. `core.chat.respond`,
  `core.memory.extract`, `hypomnema.context.retrieve`.
- **Who can register:** `core` registers built-ins; extensions can register
  operations only through declared and granted capabilities.
- **Who can invoke:** the host pipeline invokes operations. Extensions do not
  arbitrarily run registered operations against host state.
- **Model selection:** the declaration requests a model profile; the host
  resolves that profile to provider/model/version at runtime.
- **Prompting:** operations reference prompt declarations or declare a renderer;
  prompt cache placement remains owned by the prompt-plan layer.
- **Retry/rate-limit policy:** declared with host-enforced caps. Queue
  mechanics are an implementation detail, not part of the operation id.
- **Token accounting:** every model-using operation records usage against the
  operation id, provider, model, tenant, user/thread/turn where present, and
  token classes.

The registry is not an agent graph. It is a typed catalog of callable operation
contracts that the pipeline planner can compose.

---

## 2. Declaration shape

```json
{
  "envelope_version": "0",
  "kind": "operation",
  "operation": "core.memory.extract",
  "operation_version": "1.0.0",
  "provider": "core",
  "summary": "Extract durable memory candidates from recent conversation turns.",
  "visibility": "internal",
  "executor": {
    "transport": "host",
    "method": "core.operations.memory.extract",
    "timeout_ms": 120000
  },
  "input_schema": {
    "$schema": "https://json-schema.org/draft/2020-12/schema",
    "type": "object",
    "additionalProperties": false,
    "properties": {
      "user": { "$ref": "#/$defs/user_ref" },
      "thread": { "$ref": "#/$defs/thread_ref" },
      "turn_window": {
        "type": "object",
        "additionalProperties": false,
        "properties": {
          "max_messages": { "type": "integer", "minimum": 1 },
          "since_checkpoint": { "type": "string" }
        },
        "required": ["max_messages"]
      }
    },
    "required": ["user", "turn_window"],
    "$defs": {
      "user_ref": {
        "type": "object",
        "additionalProperties": false,
        "properties": { "id": { "type": "string" } },
        "required": ["id"]
      },
      "thread_ref": {
        "type": "object",
        "additionalProperties": false,
        "properties": { "id": { "type": "string" } },
        "required": ["id"]
      }
    }
  },
  "output_schema": {
    "$schema": "https://json-schema.org/draft/2020-12/schema",
    "type": "object",
    "additionalProperties": false,
    "properties": {
      "created_count": { "type": "integer", "minimum": 0 }
    },
    "required": ["created_count"]
  },
  "model": {
    "profile": "reasoning.medium",
    "capabilities": ["text_in", "json_out"],
    "selection": "host_policy",
    "allow_extension_override": false
  },
  "prompt": {
    "strategy": "prompt_plan",
    "prompts": ["core.memory.extraction"],
    "response_format": "json_schema"
  },
  "context_requirements": [
    { "slot": "user", "required": true, "sensitivity": "identity" },
    { "slot": "thread", "required": false, "sensitivity": "conversation_metadata" },
    { "slot": "turn_window", "required": true, "sensitivity": "conversation_content" }
  ],
  "scheduling": {
    "mode": "queue",
    "queue": "model_work",
    "unique": { "scope": ["tenant", "user"], "key": "memory.extract" },
    "rate_limit": { "bucket": "core.memory.extract", "per_minute": 10 }
  },
  "retry": {
    "max_attempts": 3,
    "backoff_ms": [30000, 60000, 120000],
    "retry_until_ms": 3600000,
    "rate_limit_behavior": "release_until_provider_reset",
    "retryable_errors": ["provider_rate_limited", "provider_overloaded", "timeout"]
  },
  "budget": {
    "max_prompt_tokens": 12000,
    "max_completion_tokens": 2000,
    "overflow": "truncate_with_notice"
  },
  "accounting": {
    "usage_source": "core.memory.extract",
    "record_tokens": true,
    "record_latency": true,
    "record_cache_tokens": true
  },
  "side_effects": {
    "writes": ["core.memory_candidate"],
    "emits_events": ["operation.started", "operation.completed", "operation.failed"]
  }
}
```

The split mirrors the other declaration primitives:

- **Routing envelope:** `kind`, `operation`, `operation_version`, `provider`,
  and `executor`.
- **Schemas:** `input_schema` and `output_schema` describe the callable
  contract.
- **Operation hints:** `model`, `prompt`, `context_requirements`, `scheduling`,
  `retry`, `budget`, `accounting`, and `side_effects` describe host execution
  semantics.

---

## 3. Registration and trust

Core operations are registered from code/config at boot. Extension operations
arrive during ADR-010 capability negotiation:

```json
{
  "jsonrpc": "2.0",
  "method": "extension.capabilities",
  "params": {
    "provider": "hypomnema",
    "capabilities": {
      "operations": [
        {
          "operation": "hypomnema.context.retrieve",
          "operation_version": "1.0.0",
          "hook_points": ["context.retrieve"],
          "declaration": { "...": "..." }
        }
      ]
    }
  }
}
```

The host validates:

- the operation id is provider-owned (`hypomnema.*` for Hypomnema);
- the extension has a grant for the requested hook point;
- requested context slots are allowed for that extension trust tier;
- requested model profile is allowed by tenant/admin policy;
- side effects are declared and permitted;
- retry/rate-limit values fit within host caps.

The host may accept, reject, or accept with policy overrides. The effective
registered declaration is host-owned after validation.

---

## 4. Invocation shape

The pipeline planner invokes operations by id. The request carries request-scoped
data and host-granted handles, not ambient process state.

```json
{
  "operation": "core.memory.extract",
  "operation_version": "1.0.0",
  "invocation": "core://operation-runs/01J0M2ST6FCXC6RG6AQZVG67NJ",
  "request_context": {
    "tenant": { "id": "core://tenants/personal" },
    "user": { "id": "core://users/01J0JRQ5EBGGE65M0M2W6C7S8J" },
    "thread": { "id": "core://threads/01J0JS6F9SZMG1W7V0R4SA8J76" },
    "turn_window": {
      "handle": "core://context-snapshots/01J0M2SYMAHF2E2NE0YC7DV1M6"
    }
  },
  "execution_policy": {
    "provider": "anthropic",
    "model": "claude-sonnet-4-5",
    "timeout_ms": 120000,
    "max_prompt_tokens": 12000,
    "max_completion_tokens": 2000
  }
}
```

The executor returns a structured result:

```json
{
  "status": "completed",
  "output": { "created_count": 3 },
  "usage": {
    "provider": "anthropic",
    "model": "claude-sonnet-4-5",
    "prompt_tokens": 4821,
    "completion_tokens": 612,
    "cache_read_tokens": 0,
    "cache_write_tokens": 0,
    "thought_tokens": 0
  },
  "events": [
    {
      "type": "core.memory_candidate.created",
      "ref": {
        "provider": "core",
        "type": "core.memory_candidate",
        "id": "core://memory-candidates/01J0M2T8G3R44QJ8G1S1HPQR4S"
      }
    }
  ]
}
```

Extensions can return operation usage, but the host records it. The usage row is
not trusted merely because an extension said so; it is reconciled with the host's
provider adapter when the host made the model call, or marked extension-reported
when the extension used an external provider under its own credentials.

---

## 5. Model selection

Operations do not hard-code model names in portable declarations. They request
profiles:

- `chat.frontier` — primary assistant response or similarly complex synthesis.
- `reasoning.medium` — extraction, consolidation, summarization, reranking.
- `reasoning.fast` — thread naming, lightweight classification.
- `embedding.text` — embedding generation.
- `speech.output` — text-to-speech.

Tenant/admin policy maps profiles to provider/model/version, timeouts, token
caps, and provider-specific options. This preserves Iris's useful per-operation
model choice while avoiding environment-variable sprawl as the operation count
grows.

Core can pin a specific model for a deployment if needed, but that is an
effective policy override, not the declaration's normal shape.

---

## 6. Prompt relationship

Operations may use prompts in three ways:

- `prompt_plan`: assemble one or more registered Prompt Declarations through the
  host prompt planner.
- `renderer`: call the operation executor to build its own model messages.
- `none`: embeddings, deterministic transforms, or operations that do not call a
  model.

`prompt_plan` is the default for model calls that should participate in global
ordering, context grants, budget planning, and cache-breakpoint allocation.
Operation declarations can select prompt ids, but they cannot set provider cache
controls directly.

---

## 7. Pipeline hook relationship

An operation is callable work. A hook point is where the host pipeline may call
work. The registry keeps them separate:

- `core.chat.respond` may be bound to the `response.generate` hook.
- `core.memory.extract` may be scheduled after `turn.completed`.
- `hypomnema.context.retrieve` may be a candidate implementation for
  `context.retrieve`.
- `acme.suggestion.rank` may augment `suggestions.rank`.

This lets multiple operations compete or compose at a hook point without making
the operation id itself encode scheduling semantics.

---

## 8. Token accounting

Iris's `TokenUsageSource` enum is the right naming canon but too coarse for
extensions. The host records `usage_source` as the operation id, with optional
rollups by category.

Minimum usage row fields:

- `tenant_id`
- `user_id` nullable
- `thread_id` nullable
- `turn_id` nullable
- `operation_run_id`
- `operation`
- `operation_version`
- `provider`
- `model`
- `prompt_tokens`
- `completion_tokens`
- `cache_read_tokens`
- `cache_write_tokens`
- `thought_tokens`
- `estimated` boolean
- `latency_ms`
- `created_at`

Categories are derived, not primary identity:

```json
{
  "operation": "core.memory.extract",
  "category": "extraction"
}
```

That keeps Iris-style reporting possible (`chat`, `extraction`, `summarization`,
`recall`, `embedding`) while preserving provider-owned extension names.

---

## 9. v0 operation set

Initial core operations:

| Operation | Category | Model profile | Scheduling |
|---|---|---|---|
| `core.chat.respond` | chat | `chat.frontier` | inline stream task |
| `core.thread.name` | naming | `reasoning.fast` | queue after threshold |
| `core.thread.brief.generate` | summarization | `reasoning.fast` | queue after response cadence |
| `core.conversation.summarize` | summarization | `reasoning.medium` | queue under token pressure |
| `core.memory.recall_query` | recall | `reasoning.medium` | inline before response |
| `core.memory.extract` | extraction | `reasoning.medium` | queue after turn/message threshold |
| `core.memory.consolidate` | consolidation | `reasoning.medium` | scheduled queue |
| `core.embedding.generate` | embedding | `embedding.text` | queue/inline by caller |
| `core.suggestion.rank` | suggestion | `reasoning.fast` | inline/async UI assist |

Deferred but already shaped by the same registry:

- `core.truth.crystallize`
- `core.truth.promote`
- `core.truth.consolidate`
- `core.heartbeat.decide`
- `core.heartbeat.compose`
- `core.subagent.run`
- `core.speech.synthesize`

The exact number is deliberately not sacred. "14 operations" is a useful warning
that the system will have many model calls; the registry names and governs them
instead of pretending they are one assistant call.
