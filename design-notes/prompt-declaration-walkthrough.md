# Prompt Declaration Walkthrough (Result)

> **Tracked in Linear:** [BDS-48 — ADR-018 full prompt declaration](https://linear.app/beausimensen/issue/BDS-48) · epic [BDS-37 — context & prompt machinery](https://linear.app/beausimensen/issue/BDS-37).

Worked output of the Iris deeper-dive session on system prompt assembly. This uses
Iris's prompt classes, config registration, cache-breakpoint groups, and
`RequestContext`-driven rendering as the reference case for applying ADR-013's
"declaration once, lean instance on the wire" pattern to prompts.

This is not a new entity-type envelope. It is the prompt-primitive analogue of the
ADR-013 shape: a declaration registered once by the host or an extension, plus lean
per-request render calls that carry only request-scoped inputs and return rendered
message blocks.

---

## 1. Frame

Iris validates the useful split:

- Prompt producers are registered centrally and rendered in declared order.
- Prompt classes can return zero, one, or many system messages.
- Per-request data is not baked into the prompt declaration. It arrives through a
  shared request bag (`RequestContext`) and service collaborators.
- Cache placement is not owned by individual prompts. A builder layer applies cache
  breakpoints from the ordered configuration, capped by provider limits.

The extension-friendly version keeps those positions but moves registration across
ADR-010's out-of-process boundary. An extension declares a prompt capability; the
host owns ordering, context grants, cache-breakpoint placement, rendering budget, and
final provider message assembly.

Locked positions:

- **Primitive name:** Prompt Declaration.
- **Provider:** `core` for host prompts; extension id for extension prompts.
- **Identifier:** dotted provider-owned id, e.g. `core.current_time`,
  `hypomnema.vault_context`.
- **Declaration shape:** routing envelope + structural schema + prompt-specific hints.
- **Per-request shape:** lean render request containing `prompt`, `prompt_version`,
  `request_context`, and host-granted handles or snapshots.
- **Cache policy:** centralized prompt-plan layer, not individual prompt renderers.
- **Ordering:** declared as ordered slots with numeric weights and dependency anchors;
  user/admin config may override within allowed constraints.

---

## 2. Prompt declaration envelope

```json
{
  "envelope_version": "0",
  "kind": "prompt",
  "prompt": "hypomnema.vault_context",
  "prompt_version": "1.0.0",
  "provider": "hypomnema",
  "renderer": {
    "transport": "json-rpc",
    "method": "prompt.render",
    "timeout_ms": 3000
  },
  "schema": {
    "$schema": "https://json-schema.org/draft/2020-12/schema",
    "type": "object",
    "additionalProperties": false,
    "properties": {
      "attached_entities": {
        "type": "array",
        "items": { "$ref": "#/$defs/reference" }
      },
      "message": { "type": "string" },
      "thread": { "$ref": "#/$defs/thread_context" }
    },
    "required": ["attached_entities"],
    "$defs": {
      "reference": {
        "type": "object",
        "additionalProperties": false,
        "properties": {
          "provider": { "type": "string" },
          "type": { "type": "string" },
          "id": { "type": "string" },
          "label": { "type": "string" }
        },
        "required": ["provider", "type", "id"]
      },
      "thread_context": {
        "type": "object",
        "additionalProperties": false,
        "properties": {
          "id": { "type": "string" },
          "turn_index": { "type": "integer", "minimum": 0 }
        },
        "required": ["id"]
      }
    }
  },
  "context_requirements": [
    { "slot": "message", "required": false, "sensitivity": "user_input" },
    { "slot": "thread", "required": false, "sensitivity": "conversation_metadata" },
    {
      "slot": "attached_entities",
      "required": true,
      "types": ["hypomnema.note", "hypomnema.vault"],
      "sensitivity": "entity_reference"
    }
  ],
  "output": {
    "role": "system",
    "cardinality": "zero_or_more",
    "content_type": "text/markdown",
    "empty_behavior": "omit",
    "side_effects": false
  },
  "ordering": {
    "slot": "context.dynamic",
    "weight": 40,
    "after": ["core.summary_context"],
    "before": ["core.current_time"]
  },
  "cache": {
    "eligibility": "groupable",
    "stability": "per_thread",
    "preferred_group": "thread_context"
  }
}
```

This is deliberately close to ADR-013 without pretending prompts are rendered UI
entities. The same split still matters:

- **Routing envelope:** `envelope_version`, `kind`, `prompt`, `prompt_version`,
  `provider`, and `renderer` tell the host how to dispatch.
- **JSON Schema:** describes the request data the prompt renderer can consume. It is
  structure only.
- **Prompt hints:** `context_requirements`, `output`, `ordering`, and `cache` describe
  host assembly semantics.

The declaration is sent during extension handshake or capability refresh. It is not
re-sent with every model request.

---

## 3. Render request

When the host builds a model request, it evaluates the prompt plan and calls only the
prompt declarations selected for that request.

```json
{
  "jsonrpc": "2.0",
  "id": "req_01J0JSHJ7Z4B2N1KNEE8G6RXE2",
  "method": "prompt.render",
  "params": {
    "prompt": "hypomnema.vault_context",
    "prompt_version": "1.0.0",
    "request_context": {
      "message": "Summarize the launch notes and call out decisions.",
      "thread": {
        "id": "core://threads/01J0JS6F9SZMG1W7V0R4SA8J76",
        "turn_index": 18
      },
      "attached_entities": [
        {
          "provider": "hypomnema",
          "type": "hypomnema.note",
          "id": "hypomnema://localhost/vaults/019dd737-8435-7db3-937a-0884d6b0ce63/Projects/Foo/launch.md",
          "label": "Foo launch"
        }
      ]
    },
    "budget": {
      "max_tokens": 1200,
      "overflow": "truncate_with_notice"
    }
  }
}
```

The render result is also lean:

```json
{
  "messages": [
    {
      "role": "system",
      "content_type": "text/markdown",
      "content": "## Attached Vault Context\n\n- Foo launch: ...",
      "metadata": {
        "prompt": "hypomnema.vault_context",
        "prompt_version": "1.0.0",
        "provider": "hypomnema"
      }
    }
  ],
  "usage": {
    "estimated_tokens": 187
  }
}
```

Extensions do not return provider-specific cache controls. If they do, the host strips
or rejects them for the same reason Iris strips prompt-level cache options: cache
placement must be globally coordinated.

---

## 4. RequestContext equivalent

Iris's `RequestContext` is an in-process mutable bag with user, message, images,
thread id, and recalled context. Across an extension boundary, that becomes a typed
host-supplied context snapshot plus optional handles.

```json
{
  "request_context": {
    "user": {
      "id": "core://users/01J0JRQ5EBGGE65M0M2W6C7S8J",
      "timezone": "America/Chicago",
      "locale": "en-US"
    },
    "thread": {
      "id": "core://threads/01J0JS6F9SZMG1W7V0R4SA8J76",
      "turn_index": 18
    },
    "message": {
      "text": "Summarize the launch notes and call out decisions.",
      "images": []
    },
    "attached_entities": [
      {
        "provider": "hypomnema",
        "type": "hypomnema.note",
        "id": "hypomnema://localhost/vaults/019dd737-8435-7db3-937a-0884d6b0ce63/Projects/Foo/launch.md",
        "label": "Foo launch"
      }
    ],
    "recalled_context": {
      "handle": "core://context-snapshots/01J0JSPT05WR4C6Q2J3KSDSNED"
    }
  }
}
```

The host decides whether a slot is included as:

- **Snapshot data:** stable, cheap, and safe to copy into the render request.
- **Reference:** universal entity references for provider-owned entities.
- **Handle:** host-owned temporary handle for larger or sensitive data, resolved only
  through granted methods.
- **Omitted:** unavailable, not granted, or not applicable for this request.

This keeps the extension from impersonating host state. It declares what it needs;
the host grants a concrete subset per request.

---

## 5. Ordering

Iris uses array order in `config/iris.php`. That is enough for an in-process app but too
fragile for multiple providers. The host needs a mergeable ordering model.

The v0 model is an ordered slot list:

1. `identity.static`
2. `behavior.static`
3. `capabilities.static`
4. `context.thread`
5. `context.dynamic`
6. `temporal.dynamic`
7. `final.dynamic`

Each prompt declares a slot, a numeric `weight` within that slot, and optional `before`
/ `after` anchors by prompt id. The host merges declarations using:

1. Slot order.
2. Numeric weight.
3. Dependency anchors, rejected if cyclic.
4. Provider id + prompt id as a deterministic final tiebreaker.

User/admin configuration can disable prompts, pin a provider's prompt into a different
allowed slot, or override weight. It cannot move a prompt into a slot the prompt did not
declare as compatible unless the extension is trusted for that override. This prevents a
third-party extension from quietly moving dynamic content ahead of stable identity or
behavior prompts.

Example plan:

```json
{
  "prompt_plan_version": "0",
  "entries": [
    {
      "prompt": "core.identity",
      "slot": "identity.static",
      "weight": 10,
      "cache_group": "static_prefix"
    },
    {
      "prompt": "core.autonomous_execution",
      "slot": "behavior.static",
      "weight": 20,
      "cache_group": "static_prefix"
    },
    {
      "prompt": "core.skills_available",
      "slot": "capabilities.static",
      "weight": 30,
      "cache_group": "static_prefix"
    },
    {
      "prompt": "core.pinned_skills",
      "slot": "context.thread",
      "weight": 10,
      "cache_group": "thread_context"
    },
    {
      "prompt": "core.summary_context",
      "slot": "context.thread",
      "weight": 20,
      "cache_group": "thread_context"
    },
    {
      "prompt": "hypomnema.vault_context",
      "slot": "context.dynamic",
      "weight": 40,
      "cache_group": null
    },
    {
      "prompt": "core.current_time",
      "slot": "temporal.dynamic",
      "weight": 10,
      "cache_group": null
    }
  ]
}
```

This preserves Iris's important property: prompt order is declared outside the prompt
implementation. The extension can express preferences, but the host owns the final plan.

---

## 6. Cache breakpoint placement

Cache placement is a layer over the final ordered message list, not a property of an
individual rendered message.

The host computes cache groups after ordering and rendering:

```json
{
  "cache_policy_version": "0",
  "max_breakpoints": 4,
  "groups": [
    {
      "id": "static_prefix",
      "cache": { "type": "ephemeral", "ttl": "1h" },
      "members": [
        "core.identity",
        "core.autonomous_execution",
        "core.skills_available"
      ]
    },
    {
      "id": "thread_context",
      "cache": { "type": "ephemeral" },
      "members": [
        "core.pinned_skills",
        "core.pinned_prompts",
        "core.summary_context",
        "core.cross_thread_context"
      ]
    }
  ]
}
```

Rules:

- Groups are over ordered prompt ids, not provider-local message indexes.
- A group consumes a breakpoint only if at least one member renders a non-empty message.
- The breakpoint is applied to the last non-empty message in the group.
- The host enforces the provider cap, currently modeled as `max_breakpoints`.
- Dynamic prompts default to uncached unless their declaration marks them as stable for
  a request scope (`per_deployment`, `per_user`, `per_thread`, or `per_turn`).
- Provider-specific cache controls from extensions are ignored unless the host has an
  explicit adapter for that provider option.

This generalizes Iris's "single authority for cache placement" rule. The difference is
that groups can contain prompts from multiple providers after the host has merged the
plan. A first-party Hypomnema static instruction prompt could join `static_prefix`; a
vault-context prompt that depends on attached notes should not.

---

## 7. Example core prompt declarations

### Static behavior prompt

```json
{
  "envelope_version": "0",
  "kind": "prompt",
  "prompt": "core.autonomous_execution",
  "prompt_version": "1.0.0",
  "provider": "core",
  "renderer": { "transport": "host", "method": "core.prompts.autonomous_execution" },
  "schema": {
    "$schema": "https://json-schema.org/draft/2020-12/schema",
    "type": "object",
    "additionalProperties": false,
    "properties": {}
  },
  "context_requirements": [],
  "output": {
    "role": "system",
    "cardinality": "one",
    "content_type": "text/markdown",
    "empty_behavior": "error",
    "side_effects": false
  },
  "ordering": {
    "slot": "behavior.static",
    "weight": 20
  },
  "cache": {
    "eligibility": "groupable",
    "stability": "per_deployment",
    "preferred_group": "static_prefix"
  }
}
```

### Current time prompt

```json
{
  "envelope_version": "0",
  "kind": "prompt",
  "prompt": "core.current_time",
  "prompt_version": "1.0.0",
  "provider": "core",
  "renderer": { "transport": "host", "method": "core.prompts.current_time" },
  "schema": {
    "$schema": "https://json-schema.org/draft/2020-12/schema",
    "type": "object",
    "additionalProperties": false,
    "properties": {
      "user": {
        "type": "object",
        "additionalProperties": false,
        "properties": {
          "timezone": { "type": "string" }
        },
        "required": ["timezone"]
      }
    },
    "required": ["user"]
  },
  "context_requirements": [
    { "slot": "user.timezone", "required": true, "sensitivity": "preference" }
  ],
  "output": {
    "role": "system",
    "cardinality": "one",
    "content_type": "text/markdown",
    "empty_behavior": "error",
    "side_effects": false
  },
  "ordering": {
    "slot": "temporal.dynamic",
    "weight": 10
  },
  "cache": {
    "eligibility": "none",
    "stability": "per_turn"
  }
}
```

### Pinned prompts

```json
{
  "envelope_version": "0",
  "kind": "prompt",
  "prompt": "core.pinned_prompts",
  "prompt_version": "1.0.0",
  "provider": "core",
  "renderer": { "transport": "host", "method": "core.prompts.pinned_prompts" },
  "schema": {
    "$schema": "https://json-schema.org/draft/2020-12/schema",
    "type": "object",
    "additionalProperties": false,
    "properties": {
      "thread": {
        "type": "object",
        "additionalProperties": false,
        "properties": {
          "id": { "type": "string" }
        },
        "required": ["id"]
      }
    },
    "required": ["thread"]
  },
  "context_requirements": [
    { "slot": "thread.id", "required": true, "sensitivity": "conversation_metadata" }
  ],
  "output": {
    "role": "system",
    "cardinality": "zero_or_one",
    "content_type": "text/markdown",
    "empty_behavior": "omit",
    "side_effects": false
  },
  "ordering": {
    "slot": "context.thread",
    "weight": 15,
    "after": ["core.pinned_skills"],
    "before": ["core.summary_context"]
  },
  "cache": {
    "eligibility": "groupable",
    "stability": "per_thread",
    "preferred_group": "thread_context"
  }
}
```

---

## 8. Out-of-process extension registration

During ADR-010 handshake, an extension advertises prompt capabilities:

```json
{
  "jsonrpc": "2.0",
  "id": "hello_01J0JT8P2M9FC8XM3K5JAG4RYD",
  "method": "extension.hello",
  "params": {
    "extension": {
      "id": "hypomnema",
      "version": "0.8.0"
    },
    "protocol_versions": ["0"],
    "capabilities": {
      "prompts": [
        {
          "envelope_version": "0",
          "kind": "prompt",
          "prompt": "hypomnema.vault_context",
          "prompt_version": "1.0.0",
          "provider": "hypomnema",
          "renderer": {
            "transport": "json-rpc",
            "method": "prompt.render",
            "timeout_ms": 3000
          },
          "schema": { "...": "request schema" },
          "context_requirements": [
            {
              "slot": "attached_entities",
              "required": true,
              "types": ["hypomnema.note", "hypomnema.vault"],
              "sensitivity": "entity_reference"
            }
          ],
          "output": {
            "role": "system",
            "cardinality": "zero_or_more",
            "content_type": "text/markdown",
            "empty_behavior": "omit",
            "side_effects": false
          },
          "ordering": {
            "slot": "context.dynamic",
            "weight": 40,
            "after": ["core.summary_context"],
            "before": ["core.current_time"]
          },
          "cache": {
            "eligibility": "groupable",
            "stability": "per_thread",
            "preferred_group": "thread_context"
          }
        }
      ]
    }
  }
}
```

The host validates the declaration, applies trust policy, stores the declaration, and
includes it in future prompt-plan builds. If the extension disconnects, the host omits
that prompt or uses a configured fallback; it does not keep stale rendered content as if
it were current.

---

## 9. What this changes in ADR-013

ADR-013 remains an entity schema-language decision, but the prompt exercise exposes a
general pattern worth naming:

- Declarations are cheap to cache and reason about when they are separated from
  per-request instances.
- JSON Schema is still useful for structure, but prompt declarations need assembly
  hints rather than presentation hints.
- Provider-specific instructions should not own global placement concerns: ordering,
  cache breakpoints, context grants, and token budget all belong to the host plan.
- The universal reference triple works as the bridge between entity declarations and
  prompt declarations. Prompt renderers should receive references and granted snapshots,
  not arbitrary host internals.

The likely follow-on is a small ADR or ADR-010 subsection for "host-planned prompt
assembly" rather than expanding ADR-013 beyond entity types.

---

## 10. Open questions surfaced

- **Prompt declaration vocabulary.** `kind: "prompt"` works, but the extension protocol
  may want a top-level `capability_type` wrapper so entity declarations, prompt
  declarations, tools, and hooks share one registration envelope.
- **Context grant language.** `context_requirements` needs a real vocabulary for
  sensitivity, scope, and handles before this is implementable.
- **Budget contract.** Renderers need a standard way to report truncation, omitted
  sections, and estimated token count.
- **Provider-specific cache adapters.** The policy is provider-neutral, but Anthropic's
  concrete cache fields still need an adapter. Other providers may have no equivalent.
- **Failure semantics.** Static required prompts should fail request construction when
  rendering fails. Optional context prompts should degrade with an omitted-prompt event
  that is visible in debugging.
- **User-visible ordering controls.** Admin config can override order, but the UX for
  users pinning or moving prompts is deferred.
