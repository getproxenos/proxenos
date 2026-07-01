# Schema Language - Memory / Truth / Summary Walkthrough (Result)

> **Tracked in Linear:** [BDS-42 — Memory / Truth / Summary primitives](https://linear.app/beausimensen/issue/BDS-42) · epic [BDS-35 — external-provider boundary](https://linear.app/beausimensen/issue/BDS-35).

Worked output of the Iris Session 05 handoff. This pressure-tests ADR-013's type
envelope and universal reference triple against memory-shaped entities: episodic
`Memory`, durable `Truth`, `TruthConflict`, and chained `ConversationSummary` records.

The source reference is Iris's Laravel implementation: memory/truth/summary models,
their migrations and enums, `ContextRetriever`, `TruthDistiller`, `TruthPromoter`,
`TruthCrystallizer`, and `prompts/recalled-context.blade.php`.

---

## 1. Frame

Iris treats memory-like context as a small typed graph:

- **Memory** is a recalled fact/event/preference/etc. with embedding search, access counts,
  expiry, consolidation lineage, and optional relationship to another memory.
- **Truth** is a stable statement about the user. Some truths are promoted from memories;
  others are user- or agent-created. Pinned/user/agent truths are protected from automatic
  crystallization.
- **TruthConflict** records a contradiction between an existing truth and new evidence,
  optionally linked to the memory that supplied the new evidence.
- **ConversationSummary** is a per-thread summary segment chained by `previous_summary_id`
  and scoped to a message range.

These are good ADR-013 tests because the entities are typed and renderable, but their real
value also depends on retrieval, promotion, crystallization, conflict handling, and prompt
serialization. Those behaviors should not be smuggled into the structural schema.

Locked positions from this walkthrough:

- ADR-013's **schema + presentation + routing envelope** still holds for these entities.
- The universal `{ provider, type, id, label? }` reference envelope works for memory->truth,
  truth->source-memory, truth-conflict->truth/new-memory, and summary-chain relationships.
- Lifecycle and recall behavior should be expressed as **extension augmentations** against
  these types, not as hidden requirements inside the entity schemas.
- Prompt injection needs a separate prompt-serialization declaration/hook. The presentation
  `summary` slot is not enough to express "truths always inject before dynamic memories."

For examples below, the provider is named `iris`. A host-native implementation could use
`core.memory`/`core.truth` instead; the shape is the point.

---

## 2. Reference shape

The same reference triple used by Notes and Context Sets works unchanged:

```json
{
  "provider": "iris",
  "type": "iris.memory",
  "id": "iris://users/42/memories/1001",
  "label": "Prefers concise planning notes"
}
```

Relationship fields impose type constraints at the field level:

- `memory.related_memory` accepts `iris.memory`.
- `memory.consolidated_from[]` and `memory.consolidated_into` accept `iris.memory`.
- `truth.source_memories[]` accepts `iris.memory`.
- `truth.consolidated_from[]` and `truth.consolidated_into` accept `iris.truth`.
- `truth_conflict.truth` accepts `iris.truth`; `truth_conflict.new_memory` accepts
  `iris.memory`.
- `conversation_summary.thread`, `previous_summary`, and `next_summary` accept
  `iris.thread` / `iris.conversation_summary`.

The Iris database stores local integer ids and arrays of ids. An ADR-013 provider should
emit references, not raw ids, at the typed context boundary. That keeps graph walking and
renderer dispatch uniform even when storage remains relational.

---

## 3. Memory entity

### JSON Schema

Structure only. Embeddings are deliberately omitted from the public entity shape; they are
retrieval index data, not meaningful context for display or prompt citation.

```json
{
  "$schema": "https://json-schema.org/draft/2020-12/schema",
  "type": "object",
  "additionalProperties": false,
  "properties": {
    "content": { "type": "string" },
    "source": { "type": "string" },
    "context": { "type": "string" },
    "memory_type": {
      "type": "string",
      "enum": ["fact", "preference", "goal", "event", "skill", "relationship", "habit", "context"]
    },
    "category": {
      "type": "string",
      "enum": ["personal", "professional", "hobbies", "health", "relationships", "preferences", "goals"]
    },
    "metadata": { "type": "object", "additionalProperties": true },
    "tags": { "type": "array", "items": { "type": "string" } },
    "related_memory": { "$ref": "#/$defs/reference" },
    "consolidated_from": { "type": "array", "items": { "$ref": "#/$defs/reference" } },
    "consolidated_into": { "$ref": "#/$defs/reference" },
    "consolidation_generation": { "type": "integer", "minimum": 0 },
    "access_count": { "type": "integer", "minimum": 0 },
    "expires_at": { "type": "string", "format": "date-time" },
    "last_accessed_at": { "type": "string", "format": "date-time" },
    "promotion_analyzed_at": { "type": "string", "format": "date-time" },
    "consolidated_at": { "type": "string", "format": "date-time" },
    "created": { "type": "string", "format": "date-time" },
    "modified": { "type": "string", "format": "date-time" },
    "deleted_at": { "type": "string", "format": "date-time" }
  },
  "required": ["content", "memory_type", "access_count", "consolidation_generation", "created", "modified"],
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
    }
  }
}
```

### Presentation

```json
{
  "title": { "strategy": "excerpt", "source": "/content", "max_chars": 80 },
  "summary": "/content",
  "icon": "brain",
  "status": {
    "field": "/memory_type",
    "variants": {
      "fact": { "label": "Fact" },
      "preference": { "label": "Preference" },
      "goal": { "label": "Goal" },
      "event": { "label": "Event" },
      "skill": { "label": "Skill" },
      "relationship": { "label": "Relationship" },
      "habit": { "label": "Habit" },
      "context": { "label": "Context" }
    }
  },
  "card_fields": ["/category", "/access_count", "/last_accessed_at"],
  "detail_fields": [
    "/context",
    "/tags",
    "/related_memory",
    "/consolidated_from",
    "/consolidated_into",
    "/consolidation_generation",
    "/expires_at",
    "/promotion_analyzed_at",
    "/created",
    "/modified"
  ],
  "references": [],
  "external_link": { "strategy": "provider_deeplink" },
  "content_types": [
    { "field": "/content", "type": "plain" },
    { "field": "/context", "type": "plain" }
  ]
}
```

### Envelope

```json
{
  "envelope_version": "0",
  "type": "iris.memory",
  "type_version": "1.0.0",
  "provider": "iris",
  "custom_renderer": null,
  "schema": { "...": "the JSON Schema above" },
  "presentation": { "...": "the presentation hints above" }
}
```

### Serialized instance

```json
{
  "type": "iris.memory",
  "id": "iris://users/42/memories/1001",
  "data": {
    "content": "The user prefers concise planning notes.",
    "source": "agent",
    "context": "Captured during a design review.",
    "memory_type": "preference",
    "category": "preferences",
    "tags": ["planning", "communication"],
    "related_memory": {
      "provider": "iris",
      "type": "iris.memory",
      "id": "iris://users/42/memories/914",
      "label": "Prefers short status updates"
    },
    "consolidated_from": [],
    "consolidation_generation": 0,
    "access_count": 12,
    "last_accessed_at": "2026-05-31T16:00:00Z",
    "created": "2026-05-20T10:15:00Z",
    "modified": "2026-05-31T16:00:00Z"
  }
}
```

Memory is a typed-meaning provider, unlike Hypomnema Note. Its `memory_type` enum is
provider-vouched classification, so the `status` slot is appropriate.

---

## 4. Truth entity

### JSON Schema

```json
{
  "$schema": "https://json-schema.org/draft/2020-12/schema",
  "type": "object",
  "additionalProperties": false,
  "properties": {
    "content": { "type": "string" },
    "category": {
      "type": "string",
      "enum": ["personal", "professional", "hobbies", "health", "relationships", "preferences", "goals"]
    },
    "source": {
      "type": "string",
      "enum": ["promoted", "user_created", "agent"]
    },
    "source_memories": { "type": "array", "items": { "$ref": "#/$defs/reference" } },
    "access_count": { "type": "integer", "minimum": 0 },
    "distillation_score": { "type": "integer", "minimum": 0 },
    "generation": { "type": "integer", "minimum": 0 },
    "consolidated_from": { "type": "array", "items": { "$ref": "#/$defs/reference" } },
    "consolidated_into": { "$ref": "#/$defs/reference" },
    "consolidated_at": { "type": "string", "format": "date-time" },
    "last_crystallized_at": { "type": "string", "format": "date-time" },
    "last_accessed_at": { "type": "string", "format": "date-time" },
    "is_pinned": { "type": "boolean" },
    "created": { "type": "string", "format": "date-time" },
    "modified": { "type": "string", "format": "date-time" },
    "deleted_at": { "type": "string", "format": "date-time" }
  },
  "required": ["content", "source", "access_count", "distillation_score", "generation", "is_pinned", "created", "modified"],
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
    }
  }
}
```

### Presentation

```json
{
  "title": { "strategy": "excerpt", "source": "/content", "max_chars": 80 },
  "summary": "/content",
  "icon": "badge-check",
  "status": {
    "field": "/source",
    "variants": {
      "promoted": { "label": "Promoted" },
      "user_created": { "label": "User Created" },
      "agent": { "label": "Agent Created" }
    }
  },
  "card_fields": ["/category", "/is_pinned", "/generation", "/last_accessed_at"],
  "detail_fields": [
    "/source_memories",
    "/distillation_score",
    "/access_count",
    "/consolidated_from",
    "/consolidated_into",
    "/last_crystallized_at",
    "/created",
    "/modified"
  ],
  "references": [],
  "external_link": { "strategy": "provider_deeplink" },
  "content_types": [
    { "field": "/content", "type": "plain" }
  ]
}
```

The `is_pinned` field is not just display state. In Iris it gates lifecycle: pinned truths
are always recalled and are protected from automatic crystallization. ADR-013 can expose
the field, but the behavior belongs in an augmentation declaration.

### Envelope

```json
{
  "envelope_version": "0",
  "type": "iris.truth",
  "type_version": "1.0.0",
  "provider": "iris",
  "custom_renderer": null,
  "schema": { "...": "the JSON Schema above" },
  "presentation": { "...": "the presentation hints above" }
}
```

### Serialized instance

```json
{
  "type": "iris.truth",
  "id": "iris://users/42/truths/77",
  "data": {
    "content": "The user prefers concise planning notes.",
    "category": "preferences",
    "source": "promoted",
    "source_memories": [
      {
        "provider": "iris",
        "type": "iris.memory",
        "id": "iris://users/42/memories/1001",
        "label": "The user prefers concise planning notes."
      }
    ],
    "access_count": 4,
    "distillation_score": 86,
    "generation": 1,
    "last_crystallized_at": "2026-05-30T21:45:00Z",
    "last_accessed_at": "2026-05-31T16:00:00Z",
    "is_pinned": false,
    "created": "2026-05-29T09:00:00Z",
    "modified": "2026-05-30T21:45:00Z"
  }
}
```

---

## 5. Truth conflict entity

Truth conflicts are distinct enough to be their own type rather than a field on Truth.
They have review lifecycle, proposed content, reasoning, and a nullable new-memory
reference.

```json
{
  "envelope_version": "0",
  "type": "iris.truth_conflict",
  "type_version": "1.0.0",
  "provider": "iris",
  "custom_renderer": null,
  "schema": {
    "$schema": "https://json-schema.org/draft/2020-12/schema",
    "type": "object",
    "additionalProperties": false,
    "properties": {
      "truth": { "$ref": "#/$defs/reference" },
      "new_memory": { "$ref": "#/$defs/reference" },
      "existing_content": { "type": "string" },
      "new_evidence": { "type": "string" },
      "proposed_content": { "type": "string" },
      "reasoning": { "type": "string" },
      "evidence_strength": { "type": "integer", "minimum": 0 },
      "resolution": {
        "type": "string",
        "enum": ["flagged", "auto_updated", "accepted", "rejected", "merged"]
      },
      "resolution_notes": { "type": "string" },
      "resolved_at": { "type": "string", "format": "date-time" },
      "created": { "type": "string", "format": "date-time" },
      "modified": { "type": "string", "format": "date-time" }
    },
    "required": ["truth", "existing_content", "new_evidence", "proposed_content", "reasoning", "evidence_strength", "resolution", "created", "modified"],
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
      }
    }
  },
  "presentation": {
    "title": { "strategy": "excerpt", "source": "/proposed_content", "max_chars": 80 },
    "summary": "/reasoning",
    "icon": "triangle-alert",
    "status": {
      "field": "/resolution",
      "variants": {
        "flagged": { "label": "Pending Review" },
        "auto_updated": { "label": "Auto Updated" },
        "accepted": { "label": "Accepted" },
        "rejected": { "label": "Rejected" },
        "merged": { "label": "Merged" }
      }
    },
    "card_fields": ["/truth", "/new_memory", "/evidence_strength"],
    "detail_fields": ["/existing_content", "/new_evidence", "/proposed_content", "/reasoning", "/resolution_notes", "/resolved_at", "/created"],
    "references": [],
    "external_link": { "strategy": "provider_deeplink" },
    "content_types": [
      { "field": "/existing_content", "type": "plain" },
      { "field": "/new_evidence", "type": "plain" },
      { "field": "/proposed_content", "type": "plain" },
      { "field": "/reasoning", "type": "plain" }
    ]
  }
}
```

The conflict entity confirms that ADR-013's `status` slot is not only for issue-trackers.
It applies to any provider-vouched lifecycle enum.

---

## 6. Conversation summary entity

### JSON Schema

```json
{
  "$schema": "https://json-schema.org/draft/2020-12/schema",
  "type": "object",
  "additionalProperties": false,
  "properties": {
    "thread": { "$ref": "#/$defs/reference" },
    "sequence_number": { "type": "integer", "minimum": 1 },
    "previous_summary": { "$ref": "#/$defs/reference" },
    "next_summary": { "$ref": "#/$defs/reference" },
    "summary": { "type": "string" },
    "narrative_thread": { "type": "string" },
    "emotional_thread": { "type": "string" },
    "emotional_markers": { "type": "array", "items": { "type": "object", "additionalProperties": true } },
    "emotional_intensity": { "type": "number", "minimum": 0, "maximum": 1 },
    "dominant_emotion": { "type": "string" },
    "evolving_themes": { "type": "array", "items": { "type": "string" } },
    "relationship_dynamics": { "type": "object", "additionalProperties": true },
    "unresolved_threads": { "type": "array", "items": { "type": "string" } },
    "resolved_threads": { "type": "array", "items": { "type": "string" } },
    "message_range": {
      "type": "object",
      "additionalProperties": false,
      "properties": {
        "start": { "$ref": "#/$defs/reference" },
        "end": { "$ref": "#/$defs/reference" },
        "message_count": { "type": "integer", "minimum": 0 }
      },
      "required": ["start", "end", "message_count"]
    },
    "key_facts_extracted": { "type": "array", "items": { "type": "string" } },
    "accomplishments": { "type": "array", "items": { "type": "string" } },
    "relevant_files": { "type": "array", "items": { "type": "string" } },
    "key_decisions": { "type": "array", "items": { "type": "string" } },
    "active_goals": { "type": "array", "items": { "type": "string" } },
    "metadata": { "type": "object", "additionalProperties": true }
  },
  "required": ["thread", "sequence_number", "summary", "message_range"],
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
    }
  }
}
```

### Presentation

```json
{
  "title": { "strategy": "template", "template": "Summary #{/sequence_number}" },
  "summary": "/summary",
  "icon": "message-square-text",
  "card_fields": ["/thread", "/message_range/message_count", "/dominant_emotion"],
  "detail_fields": [
    "/narrative_thread",
    "/emotional_thread",
    "/evolving_themes",
    "/unresolved_threads",
    "/resolved_threads",
    "/key_facts_extracted",
    "/accomplishments",
    "/relevant_files",
    "/key_decisions",
    "/active_goals",
    "/previous_summary",
    "/next_summary"
  ],
  "references": [],
  "external_link": { "strategy": "provider_deeplink" },
  "content_types": [
    { "field": "/summary", "type": "plain" },
    { "field": "/narrative_thread", "type": "plain" },
    { "field": "/emotional_thread", "type": "plain" }
  ]
}
```

The `title` example uses a template strategy. That is a candidate extension of ADR-013's
strategy-object form; the current ADR explicitly allows strategy objects, but has only
exercised excerpt/provider-deeplink examples. Without a template strategy, the provider
can materialize a `title` field before serialization.

### Envelope

```json
{
  "envelope_version": "0",
  "type": "iris.conversation_summary",
  "type_version": "1.0.0",
  "provider": "iris",
  "custom_renderer": null,
  "schema": { "...": "the JSON Schema above" },
  "presentation": { "...": "the presentation hints above" }
}
```

### Serialized instance

```json
{
  "type": "iris.conversation_summary",
  "id": "iris://users/42/threads/9/summaries/12",
  "data": {
    "thread": {
      "provider": "iris",
      "type": "iris.thread",
      "id": "iris://users/42/threads/9",
      "label": "Workspace architecture"
    },
    "sequence_number": 12,
    "previous_summary": {
      "provider": "iris",
      "type": "iris.conversation_summary",
      "id": "iris://users/42/threads/9/summaries/11",
      "label": "Summary #11"
    },
    "summary": "The conversation settled the ADR-013 type envelope and identified memory lifecycle as augmentation-shaped.",
    "narrative_thread": "The design moved from display schemas toward lifecycle-aware context.",
    "message_range": {
      "start": {
        "provider": "iris",
        "type": "iris.conversation",
        "id": "iris://users/42/conversations/5001",
        "label": "First message"
      },
      "end": {
        "provider": "iris",
        "type": "iris.conversation",
        "id": "iris://users/42/conversations/5038",
        "label": "Last message"
      },
      "message_count": 38
    },
    "key_facts_extracted": ["ADR-013 references remain universal."],
    "active_goals": ["Design lifecycle augmentation hooks."]
  }
}
```

The summary-chain relationship is a plain entity graph. No new reference envelope is
needed for chain traversal.

---

## 7. Recall and prompt serialization

Iris recall has a deterministic prompt shape:

1. Generate search queries from recent thread context.
2. Retrieve all pinned truths plus ranked unpinned truths.
3. Retrieve semantic memories.
4. Record access counts.
5. Inject as:
   - `Core Truths`
   - `Contextually Relevant Memories`

ADR-013 presentation hints can render a Truth or Memory card, but they cannot express this
ordering and injection policy. That belongs in a prompt-serialization declaration or
augmentation hook, for example:

```json
{
  "target": "iris.truth",
  "hook": "prompt.serialize",
  "section": "Core Truths",
  "order": 10,
  "selector": {
    "mode": "recall",
    "include_pinned": true,
    "max_dynamic": 10
  },
  "template": "- {{ content }}"
}
```

That shape should be designed with the prompt-declaration work, not bolted onto
`presentation.summary`.

---

## 8. Lifecycle as augmentation

Iris bakes in a rich lifecycle:

- Access counts and recency affect recall ranking.
- Frequently accessed memories become candidates for truth promotion.
- Promotion uses LLM analysis to decide whether a memory contains a stable truth.
- Similar memories may crystallize an existing truth instead of creating a new truth.
- Contradictions create `TruthConflict` records or auto-update unprotected truths when
  evidence is strong enough.
- Pinned/user-created/agent-created truths are protected from automatic crystallization.
- Consolidation creates lineage across memories and truths.

ADR-013 should expose the structural fields that make those states visible. It should not
define the lifecycle engine. The extension augmentation surface needs lifecycle hooks such
as:

- `recall.rank` for semantic/frequency/recency ranking.
- `prompt.serialize` for injected context sections.
- `entity.promote` from `iris.memory` to `iris.truth`.
- `entity.crystallize` for incorporating new evidence into `iris.truth`.
- `entity.conflict.detect` / `entity.conflict.resolve` for contradiction handling.
- `entity.consolidate` for lineage-producing merges.

These augmentations are type-targeted and policy-bearing. They need trust, ordering, and
user-control rules; those concerns are outside the schema envelope.

---

## 9. Decisions and gaps

### Decisions locked this session

- **ADR-013 holds for memory-like entities.** Memory, Truth, TruthConflict, and
  ConversationSummary can all be declared as JSON Schema + presentation hints + routing
  envelope.
- **Universal references hold.** Memory->Memory, Truth->Memory, TruthConflict->Truth,
  TruthConflict->Memory, and Summary->Summary relationships use the same reference triple.
- **Typed lifecycle enums may use `status`.** `memory_type`, `truth.source`, and
  `truth_conflict.resolution` are provider-vouched typed meaning, unlike Hypomnema
  frontmatter.
- **Provider-local ids should be normalized to references at the boundary.** Arrays like
  `source_memory_ids` are storage details; typed context instances should emit
  `source_memories[]` reference triples.
- **Embeddings stay out of entity data.** Embeddings are retrieval indexes, not user-facing
  typed context fields.
- **Lifecycle stays augmentation-shaped.** Promotion, crystallization, conflict detection,
  consolidation, recall ranking, and prompt injection are not ADR-013 schema fields.

### Gaps to carry forward

- **Prompt serialization declaration.** ADR-013's presentation slots cannot express Iris's
  `Core Truths` before dynamic `Memories` injection policy.
- **Augmentation manifest shape.** The host needs a concrete way to register lifecycle and
  recall hooks against `provider.type`.
- **Relationship field constraints.** The reference triple is universal, but schemas need a
  standard way to say "this reference field accepts only `iris.memory`" without inventing a
  new envelope. A sibling hint or JSON Schema annotation may be enough.
- **Template strategy.** Summary titles want a computed title such as `Summary #12`. Either
  ADR-013 should bless a small `template` strategy, or providers should materialize title
  fields.
- **Soft-deleted references.** Truths intentionally retain source-memory references that may
  be soft-deleted. Renderers/resolvers need a standard degraded state for referenced
  entities that exist only for provenance.
- **System vs. user-visible fields.** Counts, scores, generations, and analyzed timestamps
  are useful for debugging and lifecycle policy but noisy in normal cards. Presentation
  hints can hide them, but permission/trust rules may also need to suppress them.
