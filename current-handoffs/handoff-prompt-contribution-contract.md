# Handoff — `PromptContribution` shape + ordered-contributions contract (step-02 chunk 7)

**For:** Lane D (system-prompt contributions).
**Status:** shipped by the entity-context lane; **Lane D must thumbs-up this shape
before step-02 merges to `main`.** Not a blocker for this lane landing — if Lane D
pushes back, the change is local to `PromptAssembler` + `ChatRespondLoop`.

## The shape (decision 7)

```php
final readonly class PromptContribution
{
    public function __construct(
        public int    $weight,   // lower sorts earlier
        public string $role,     // 'system' | 'user' | 'assistant' (validated)
        public string $text,
    ) {}
}
```

Deliberately minimal — `{ weight, role, text }` and nothing else. Richer machinery
(cache breakpoints, segment ids, ADR-018 ordering) is out of scope for v0.

## Ordered-contributions contract

Both lanes emit `PromptContribution[]`; `ChatRespondLoop` sorts by `weight`
ascending and folds them into the `MessageBag`, then appends the conversation
history. The agreed order is:

```
[ systemPrompt , entityContext , conversationHistory ]
```

- **Lane D** ships **system-prompt** contributions (sort ahead — use a weight
  **below** `PromptAssembler::ENTITY_CONTEXT_WEIGHT = 100`, e.g. `0`).
- **This lane** ships **entity-context** contributions at weight `100`.
- **Conversation history** is appended by the loop after all contributions (it
  is not a `PromptContribution`).

Either lane can land first. The assembler/loop tolerate **zero** contributions of
the other kind (a thread with no attachments yields `[]`; a turn with no system
prompt simply has no system-prompt contribution).

## What Lane D needs to confirm

1. `{ weight, role, text }` is sufficient for the system-prompt lane (no extra
   fields needed for v0).
2. Weight convention: system prompt `< 100`, entity context `= 100`.
3. The loop owns the fold + sort; lanes only return contributions.
