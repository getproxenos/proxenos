# Schema Language - Context Set + Vault-Slice Walkthrough (Result)

Worked output of the second schema-language handoff prompt. This builds on the
Note walkthrough's envelope and reference triple, then pressure-tests the shape against a
host-native collection with heterogeneous membership and a Hypomnema vault-slice stored as
a query-shaped reference.

This document is an input to the ADR-013 / document-update work. It locks the
working shape for universal entity references, query-shaped membership references, and
heterogeneous member rendering.

---

## 1. Frame

The primitive is named **Context Set**.

A Context Set is a durable, host-native, project-style collection of context references.
It is not the transient set of context currently attached to a conversation. A conversation
may attach a Context Set; the host then resolves the Context Set's members into usable
context for rendering, suggestion, and prompt serialization.

The name is intentionally not `Project`. Claude.ai and Linear already use "Project" for
domain-specific concepts, and this primitive is cross-cutting: it can collect Notes,
repositories, conversations, Linear projects, future artifacts, and provider queries. It is
also not `Workspace`, which is usually an account/team/container concept. `Context Set`
is less casual, but it says what the primitive is: a named set of context references.

**Locked positions.**

- **Provider:** `core`. Context Set is owned by the host, not an extension provider.
- **Type:** `core.context_set`.
- **Membership:** ordered by the user in v0.
- **Members:** concrete entity references or query-shaped references.
- **Augmentations:** provider-specific actions, summaries, suggestions, and lifecycle
  hooks remain extension-shaped. They are not part of the primitive schema.

---

## 2. Reference shapes

### Concrete entity reference

Concrete references use the universal triple established by the Note walkthrough:

```json
{
  "provider": "hypomnema",
  "type": "hypomnema.note",
  "id": "hypomnema://localhost/vaults/019dd737-8435-7db3-937a-0884d6b0ce63/Decisions/streaming-protocol.md",
  "label": "Streaming Protocol Decision"
}
```

This is the cross-provider reference envelope. There are not separate envelopes for Note
references, GitHub repository references, Linear issue references, and so on. Type-specific
constraints are layered by the field that accepts the reference, not by changing the
reference envelope itself.

For Context Set membership, the field accepts any entity reference. The renderer and
resolver dispatch through `provider` + `type`, then look up that type's schema and
presentation hints.

### Query-shaped reference

Query-shaped references are a distinct membership kind. They store a provider search
request plus the expected type of results:

```json
{
  "kind": "query",
  "label": "Foo project notes",
  "query": {
    "provider": "hypomnema",
    "modality": "filesystem",
    "query": "**/*.md",
    "scope": {
      "vaults": ["019dd737-8435-7db3-937a-0884d6b0ce63"],
      "prefix": "Projects/Foo/"
    },
    "limit": 50,
    "options": {}
  },
  "resolves_to": {
    "provider": "hypomnema",
    "type": "hypomnema.note"
  }
}
```

This is not a generic `Query` entity in v0. The query only has identity as part of the
Context Set member that stores it. If saved searches later need independent lifecycle,
permissions, sharing, or attachment outside Context Sets, they can graduate into a real
entity type without changing the member distinction.

`resolves_to` is type intent, not a cached result. Resolving the member means running the
stored request and receiving normalized search results. The query is run at attachment
time and re-run when the Context Set is viewed or serialized, subject to caching and
prompt-budget policy outside this walkthrough.

---

## 3. Context Set JSON Schema

Structure only. Display choices live in presentation hints.

```json
{
  "$schema": "https://json-schema.org/draft/2020-12/schema",
  "type": "object",
  "additionalProperties": false,
  "properties": {
    "name": { "type": "string" },
    "description": { "type": "string" },
    "members": {
      "type": "array",
      "items": { "$ref": "#/$defs/member" }
    },
    "created": { "type": "string", "format": "date-time" },
    "modified": { "type": "string", "format": "date-time" }
  },
  "required": ["name", "members", "created", "modified"],
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
    "reference_member": {
      "type": "object",
      "additionalProperties": false,
      "properties": {
        "kind": { "const": "reference" },
        "ref": { "$ref": "#/$defs/reference" },
        "note": { "type": "string" }
      },
      "required": ["kind", "ref"]
    },
    "query_request": {
      "type": "object",
      "additionalProperties": false,
      "properties": {
        "provider": { "type": "string" },
        "modality": { "type": "string" },
        "query": { "type": "string" },
        "scope": {
          "type": "object",
          "additionalProperties": false,
          "properties": {
            "vaults": {
              "type": "array",
              "items": { "type": "string" }
            },
            "prefix": { "type": "string" }
          }
        },
        "limit": { "type": "integer", "minimum": 1 },
        "options": {
          "type": "object",
          "additionalProperties": true
        }
      },
      "required": ["provider", "modality", "query"]
    },
    "type_constraint": {
      "type": "object",
      "additionalProperties": false,
      "properties": {
        "provider": { "type": "string" },
        "type": { "type": "string" }
      },
      "required": ["provider", "type"]
    },
    "query_member": {
      "type": "object",
      "additionalProperties": false,
      "properties": {
        "kind": { "const": "query" },
        "label": { "type": "string" },
        "query": { "$ref": "#/$defs/query_request" },
        "resolves_to": { "$ref": "#/$defs/type_constraint" },
        "note": { "type": "string" }
      },
      "required": ["kind", "label", "query", "resolves_to"]
    },
    "member": {
      "oneOf": [
        { "$ref": "#/$defs/reference_member" },
        { "$ref": "#/$defs/query_member" }
      ]
    }
  }
}
```

**Annotations.**

- **`members` is ordered.** User-defined order is the canonical v0 order for display and
  prompt construction. Renderers may offer grouped views, but they must preserve the stored
  order as the default.
- **`member.kind` is the discriminator.** `reference` members point at an already-known
  entity. `query` members store a request that resolves to zero or more entities.
- **`reference` is universal.** The Context Set schema reuses the Note walkthrough's
  triple rather than inventing provider-specific reference objects.
- **`query_request` mirrors the search note.** It stores the normalized host-facing search
  request: `provider`, `modality`, `query`, optional `scope`, optional `limit`, and open
  modality-specific `options`.
- **`resolves_to` constrains the result type.** It says what type the query is expected to
  return. The actual resolution output uses normalized search results, not embedded entity
  bodies.

---

## 4. Presentation hints

```json
{
  "title": "/name",
  "summary": "/description",
  "icon": "folder-kanban",
  "card_fields": ["/members", "/modified"],
  "detail_fields": ["/description", "/members", "/created", "/modified"],
  "references": [],
  "external_link": null,
  "content_types": [
    { "field": "/description", "type": "markdown" }
  ]
}
```

**Rendering convention for heterogeneous members.**

The member list uses the stored order by default. Each concrete reference renders through
the referenced type's card hints. Each query member renders as a collapsible group with
its own label, provider/modality metadata, and resolved result rows. Result rows use the
target entity's card hints plus any evidence overlay defined by the search result shape.

Clients may add a secondary "group by type/provider" view for scanning large sets. That is
a view mode, not the stored order.

---

## 5. Envelope

```json
{
  "envelope_version": "0",
  "type": "core.context_set",
  "type_version": "1.0.0",
  "provider": "core",
  "custom_renderer": null,
  "schema": { "...": "the JSON Schema from section 3" },
  "presentation": { "...": "the hints object from section 4" }
}
```

Context Set does not need a custom renderer for v0. A generic schema-driven renderer can
show its scalar fields and delegate member cards to the referenced entity type
declarations.

---

## 6. Serialized Context Set instance

```json
{
  "type": "core.context_set",
  "id": "core://context-sets/01HZ7P7DC6QB7ZP6R6YV7F0P2V",
  "data": {
    "name": "Foo Launch",
    "description": "Reusable context for the Foo launch workstream.",
    "members": [
      {
        "kind": "reference",
        "ref": {
          "provider": "hypomnema",
          "type": "hypomnema.note",
          "id": "hypomnema://localhost/vaults/019dd737-8435-7db3-937a-0884d6b0ce63/Decisions/streaming-protocol.md",
          "label": "Streaming Protocol Decision"
        }
      },
      {
        "kind": "query",
        "label": "Foo project notes",
        "query": {
          "provider": "hypomnema",
          "modality": "filesystem",
          "query": "**/*.md",
          "scope": {
            "vaults": ["019dd737-8435-7db3-937a-0884d6b0ce63"],
            "prefix": "Projects/Foo/"
          },
          "limit": 50,
          "options": {}
        },
        "resolves_to": {
          "provider": "hypomnema",
          "type": "hypomnema.note"
        }
      },
      {
        "kind": "reference",
        "ref": {
          "provider": "github",
          "type": "github.repo",
          "id": "github://repos/acme/foo",
          "label": "acme/foo"
        },
        "note": "Implementation repository for the Foo launch."
      }
    ],
    "created": "2026-05-22T15:14:00Z",
    "modified": "2026-05-23T09:30:00Z"
  }
}
```

The first and third members are concrete references. The second member is a stored search:
it is stable as a member, but its resolved result list is dynamic.

---

## 7. Standalone vault-slice serialization

A Hypomnema folder slice is a query-shaped reference over a vault prefix. This is the
canonical v0 vault-slice example because `hmn 0.7.1` exposes folder restriction through
`--prefix`. Tag slices such as `tag = #project-foo` remain a desired shape, but there is
no real `hmn` tag modality yet; that stays provisional until Hypomnema exposes one.

### Stored reference

```json
{
  "kind": "query",
  "label": "Foo project notes",
  "query": {
    "provider": "hypomnema",
    "modality": "filesystem",
    "query": "**/*.md",
    "scope": {
      "vaults": ["019dd737-8435-7db3-937a-0884d6b0ce63"],
      "prefix": "Projects/Foo/"
    },
    "limit": 50,
    "options": {}
  },
  "resolves_to": {
    "provider": "hypomnema",
    "type": "hypomnema.note"
  }
}
```

### Resolved result

```json
{
  "provider": "hypomnema",
  "modality": "filesystem",
  "truncated": false,
  "results": [
    {
      "ref": {
        "provider": "hypomnema",
        "type": "hypomnema.note",
        "id": "hypomnema://localhost/vaults/019dd737-8435-7db3-937a-0884d6b0ce63/Projects/Foo/plan.md",
        "label": "plan"
      },
      "evidence": {
        "kind": "none"
      },
      "meta": {
        "content_hash": "sha256:9c7d4d0bc2e6f32d0e7a0fa9a77526f75c833f7b4a2e6b4e0f93a3d64e7b1a11",
        "mtime": "2026-05-21T18:04:10Z",
        "size": 4218
      }
    },
    {
      "ref": {
        "provider": "hypomnema",
        "type": "hypomnema.note",
        "id": "hypomnema://localhost/vaults/019dd737-8435-7db3-937a-0884d6b0ce63/Projects/Foo/risks.md",
        "label": "risks"
      },
      "evidence": {
        "kind": "none"
      },
      "meta": {
        "content_hash": "sha256:1374bc52c662b8a0f73e7fd97f0bb5fa6c54e1e37dd98ce8f94e2a5069c12068",
        "mtime": "2026-05-22T08:44:03Z",
        "size": 1912
      }
    }
  ]
}
```

The stored reference differs from a concrete Note reference in two ways:

- It has no entity `id`; the query member is not itself a provider entity.
- It resolves to a result list, not one target. Each result carries its own concrete
  `ref` triple.

---

## 8. Decisions and gaps

### Decisions locked this session

- **Primitive name:** Context Set.
- **Type identity:** `core.context_set`, owned by the host's `core` provider namespace.
- **Concrete references:** one universal `{ provider, type, id, label? }` envelope.
- **Polymorphic membership:** `members[]` is a discriminated union over `reference` and
  `query` member objects.
- **Query-shaped references:** stored search requests with `resolves_to`; not standalone
  `Query` entities in v0.
- **Renderer default:** user-defined order first, grouped type/provider views second.
- **Vault-slice v0:** folder/prefix slices are concrete; tag slices are provisional.

### Gaps to carry forward

- **Tag slices.** `tag = #project-foo` needs a real Hypomnema tag search/filter surface or
  a declared metadata convention.
- **Saved searches as entities.** Query members may become first-class entities later if
  they need sharing, lifecycle, or independent attachment.
- **Prompt serialization policy.** This walkthrough defines the data shape, not the
  expansion depth, prompt-budget behavior, or cache invalidation policy for resolved query
  members.
- **Augmentation surface.** Context Set actions and provider-specific summaries are still
  extension-shaped. The hook surface should be designed with the future artifact-default
  work in mind.
- **Large result sets.** The search shape still only has `limit` + `truncated`; cursor or
  offset pagination remains a v0 gap.
