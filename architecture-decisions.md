# Architecture Decisions

Lightweight ADRs. Each entry: the decision, the rationale in a few sentences, and the main alternatives ruled out. The point is to head off "have you considered X?" detours in design conversations where X has already been considered.

Format is intentionally loose. Date entries when they're adopted; mark superseded rather than deleting if a decision changes.

---

## ADR-001: Server-authoritative state with resumable streaming

**Decision.** The server is the source of truth for thread and message state. Assistant message generation writes to durable storage as tokens stream, not just to the websocket. Clients are subscribers, not owners. Disconnects do not lose responses; reconnects resume from a cursor.

**Rationale.** This is the dominant pattern across serious AI chat products (ChatGPT, Claude.ai, modern Vercel AI SDK patterns) because it solves several problems at once: offline tolerance, multi-device sync, background generation, parallel monitoring. The opposite pattern — client-authoritative state, server-streams-to-websocket-and-forgets — fails on disconnect and blocks multi-device use. Iris is no longer evidence against this direction: its current code is server-authoritative with Redis replay and final Postgres aggregates, which validates the general shape while leaving this design's fuller event-sourcing choice to ADR-004.

**Ruled out.**
- Client-persisted state. Fails on disconnect; blocks multi-device. Iris is no longer an example of this failure mode, but the failure mode remains the one ADR-001 avoids.
- Server-state-without-resumability. Cheaper to build but loses the "ask a question, walk away, come back to the answer" UX, which is one of the main motivating use cases.

---

## ADR-002: ExternalStoreRuntime as the frontend integration shape

**Decision.** The frontend uses assistant-ui's `ExternalStoreRuntime` pattern, where message state lives in a store outside the runtime and the runtime is a view onto it. The store is populated by a server subscription (websocket or SSE) plus cursor-based replay from ADR-004. See `design-notes/streaming-runtime-notes.md`.

**Rationale.** This is the runtime shape that lets server-authoritative state flow naturally. `LocalRuntime` would put the frontend back in charge of state, defeating the point. `DataStream` would tie the backend to the Vercel AI SDK wire format. `ExternalStoreRuntime` imposes no wire protocol — the backend can be anything that can populate the store. That freedom matters given the Symfony core and event-log protocol decisions.

assistant-ui provides the runtime and UI affordance layer; it does not replace the host-owned streaming store. The application still owns live subscription, event normalization, idempotent folding, replay/resume, cancellation commands, branch state, and side-payload fetches.

**Ruled out.**
- `LocalRuntime` (frontend owns state). Wrong direction.
- `DataStream Runtime`. Too tied to one wire protocol; over-constrains the backend.
- `AssistantTransport`. Interesting for fully-agent-shaped workloads, but heavier than needed here.

---

## ADR-003: Typed context entities with a provider registry

**Decision.** Context items are first-class typed entities with a pluggable provider per type. Each provider implements `search`, `render`, `serialize-for-prompt`, and (optionally) `suggest`. New context types are added by registering a new provider on both server and client.

**Rationale.** Generic blob-RAG isn't enough for the domains I care about. Products have Parts; Notes have backlinks; Conversations have turns. Treating these as typed entities with relationships lets the UI (drilldown, hierarchical picker, citation rendering) and the suggestion engine (graph walks, type-aware filtering) do real work. The provider pattern is borrowed from Continue.dev and is well-validated as the right shape for this kind of extensibility.

This decision is the entity-type instance of the broader host-mediated extension pattern; see ADR-010 for the general principle and ADR-012 for the rendering side.

**Ruled out.**
- Flat document-blob RAG. Loses too much structure.
- Hard-coded types. Doesn't scale to "I want to add a `Recipe` type in my personal vault and a `Customer` type at work without forking the app."

---

## ADR-004: Event-sourced persistence; the event log is the wire protocol

**Decision.** Thread state is persisted as an ordered, durable event log per thread. Events have monotonic sequence numbers per thread. Clients subscribe by cursor; reconnect by cursor. Current message state, turn status, tool-call state, citations, artifacts, and connector delivery status are projections over the event log.

Canonical means the durable Postgres event log is the source of truth, not a short-lived transport buffer and not the folded message rows. Projections exist for efficient reads and search, but they are rebuildable. Redis-shaped storage, if introduced, is a live-delivery optimization only: a tail cache / replay buffer populated from committed events, never the system of record. See `design-notes/event-sourced-conversations.md`.

**Rationale.** The events streamed to clients and the events persisted to the database are the same logical events. This unifies resumability, audit trail, connector delivery, and "conversation as referenceable entity" concerns into one design instead of two. Iris validates the reconnect-by-sequence pattern, but takes a more conservative storage position: Redis keeps 600 seconds of wire replay and Postgres keeps final aggregates. This design deliberately takes the fuller event-sourcing branch because referenceable conversations, rebuildable projections, branch/retry history, and extension-visible stream events all benefit from one durable source.

Implementation starts with Postgres as both event store and replay source, using polling or `LISTEN/NOTIFY` for live wakeups. Redis Streams or another broker can be added later for fan-out/backpressure, but only after the write path commits to Postgres.

**Ruled out.**
- Snapshot-only persistence. Loses the streaming reconstruction story.
- Redis as canonical event store. Good live replay buffer; wrong durability, backup, query, and archival posture for core state.
- Iris-style "TTL replay plus final aggregate" as the core model. It is a proven simpler point, but it leaves conversation history, branch/retry semantics, and future conversation-as-entity work dependent on lossy aggregates.
- CRDT-based state. Overkill for single-author messages; reconsider only if collaborative editing of messages becomes a feature.

---

## ADR-005: Multi-tenancy baked in from day one

**Decision.** Schema includes a tenant/workspace identifier on every relevant table from the start, even though I'm the only user initially. Permissions are modeled in the schema (even if everything is `owner` for now).

**Rationale.** Retrofitting multi-tenancy is famously painful. An unused column or an always-true permission check costs essentially nothing now and saves a major migration later. Since multi-user is on the explicit roadmap, deferring this would be a known mistake.

**Ruled out.**
- "Single user now, refactor later." Cheapest now, most expensive later.

---

## ADR-006: Personal-first, team-eventually

**Decision.** First useful version targets one user (me) against my own structured data (Obsidian vault via Hypomnema, exported Claude conversations). Multi-user/group concerns are designed for but not built until the personal version has been used daily for a meaningful period.

**Rationale.** The interesting questions (how do suggestions work, does conversation-as-entity make sense, do the typed-entity affordances pay off) can all be answered with one user and real data. Multi-user adds auth, sync conflicts, permissions, and UX considerations that dilute focus. Dogfooding pressure-tests the abstraction before it gets multi-user complexity layered on.

**Ruled out.**
- Multi-user from day one. Too much surface area before the core concept is proven.
- Personal-only forever. Some abstractions (tenancy, conversation-as-entity sharing) need at least one peer to design well.

---

## ADR-007: PHP/Symfony for the core; extensions are language-agnostic

**Decision.** The core application is PHP/Symfony with Doctrine (using CTI for typed context entities). Extensions, including MCP servers, suggestion providers, custom pipeline steps, and AI-adjacent services, may be written in any language consistent with ADR-010's subprocess + JSON-RPC model.

**Rationale.** PHP/Symfony fits prior expertise, deploys cleanly on Coolify (matching Iris's existing topology — see ADR-011), and offers strong libraries for the core's needs: Doctrine for persistence and typed inheritance, Messenger for async work, Mercure for SSE streaming, Workflow for state machines. CTI maps directly to the typed-context-entity abstraction central to ADR-003 and ADR-010. The original ecosystem concern — that most AI tooling is in TS/Python — is mitigated by ADR-010: AI-adjacent work lives in extensions, which can use whatever language fits the task.

**Ruled out.**
- Node/TypeScript for the core. Strongest AI ecosystem, but doesn't outweigh the velocity loss from working in a less familiar stack, given the extension architecture neutralizes the ecosystem advantage.
- Python for the core. Strong AI ecosystem, but a weaker fit for the domain modeling and persistence shape than Doctrine offers, and not a strong existing-expertise match.
- Go for the core. Excellent for streaming and event infrastructure, but heavier-handed for the domain layer than needed.

**Open questions surfaced.**
- Streaming approach (Mercure vs. a dedicated streaming service in another language vs. FrankenPHP's native streaming).
- Embedding pipeline location (in-core via pgvector for simple cases vs. extension for richer pipelines).
- MCP SDK quality in PHP (extensions in other languages are an acceptable workaround if needed).

---

## ADR-008: Multi-model orchestration internal, single assistant external

**Decision.** Following Iris's "14 operations" pattern, the system orchestrates multiple model calls internally (for entity extraction, suggestion ranking, memory updates, primary response, etc.), but presents a single logical assistant to the user. The user does not pick models.

**Rationale.** Model selection per operation is a backend concern. Hiding the orchestration keeps the user-facing surface simple and lets internal model choices evolve (cheaper models for routine ops, frontier models for primary response) without UI churn. This also means no need to expose an OpenAI-compatible endpoint unless explicitly desired for compatibility with other frontends.

**Ruled out.**
- User-facing model picker (à la Open WebUI, LibreChat). Not the product this is.
- Single model for everything. Wasteful and inflexible.

---

## ADR-009: assistant-ui as the component library

**Decision.** The frontend builds on assistant-ui's primitives (Thread, Message, Composer, ThreadList, ActionBar) and uses its shadcn-themed components as the styling baseline. AI Elements may be mixed in for specific renderers where useful, but the runtime layer is assistant-ui's.

**Rationale.** assistant-ui solves the boring-but-load-bearing chat UI problems (streaming display, message lists, attachments, branching UI, code blocks, accessibility) that are not worth reinventing. The runtime abstraction is the right shape (see ADR-002). Composability with shadcn means UI design stays unconstrained.

Iris shows the cost of building this layer by hand: reducer/recovery/channel hooks plus custom composer, message list, tool display, retry, and fork behavior. The useful lesson to keep is not the custom component stack, but the reconciliation test cases. assistant-ui owns the chat runtime surface; the host store still owns event-log reconciliation. See `design-notes/streaming-runtime-notes.md`.

**Ruled out.**
- Building chat primitives from scratch. Months of work for no differentiation.
- Vercel AI SDK + AI Elements as the whole frontend story. Pushes toward DataStream runtime, which we've ruled out (ADR-002).
- LibreChat / Open WebUI as a base. Wrong shape — those are products, not libraries.

---

## ADR-010: Host-mediated extension surface

**Decision.** Extensions interact with the host through a defined set of primitive APIs — entity type declarations (extending ADR-003), suggestion proposals, pipeline lifecycle subscriptions, UI affordance requests, and tool exposures via MCP. Extensions never directly manipulate UI components, host internal state, or other extensions. The host owns all control surfaces; extensions declare intent, and the host decides how that intent surfaces. Extensions run as separate processes (typically additional Docker Compose services) and communicate with the host over a JSON-RPC-shaped protocol with capability negotiation and explicit protocol versioning from v0.

**Rationale.** The pattern is well-validated by MCP, LSP, DAP, and ACP — all "host ↔ peer" protocols in the same family. Host-mediated primitives give us: language-agnostic extensions (subprocess + JSON-RPC), forward and backward compatibility (capability negotiation), trust tiers (the host controls which primitives are exposed to which extensions), native-client portability (any frontend can implement the same host primitives), and a stable extension contract that survives host internal changes. This generalizes ADR-003's provider pattern across the broader extension surface and concretizes the "extensible without forking" commitment that has motivated the architecture from the start.

MCP is adopted specifically for tool-shaped capabilities (tools, resources, prompts). The broader extension primitives — entity declarations, suggestions, pipeline hooks, UI affordances — layer alongside MCP using the same wire shape (JSON-RPC over a transport) with additional message types. The boundary between "what's MCP" and "what's our own primitives" is functional: MCP where it fits, our own where it doesn't.

Transport connectors are one concrete primitive in this surface. A connector is an out-of-process transport adapter that declares ingress and delivery capabilities, submits normalized inbound messages, and subscribes to host-owned stream events for delivery. Identity resolution stays in the host: connectors may report transport identity claims, but the host maps those claims to users, tenants, permissions, and conversation visibility. The gateway owns request construction, durable message/event persistence, response-pipeline execution, cancellation state, and recording; the connector owns transport parsing, transport auth/signature checks, delivery formatting, rate limits, and acknowledgements. See `design-notes/connector-primitive-notes.md`.

**Ruled out.**
- In-host plugin model (extensions loaded into the host's process). Couples extensions to host internals; defeats native-client portability; makes trust tiering hard; recreates exactly the Iris pain this project is trying to escape.
- Per-surface extension APIs (a separate "suggestion plugin" API, a separate "UI plugin" API, and so on). More surface area to maintain; loses the unifying pattern.
- ACP as the wire protocol. Its primitives are editor-shaped (files, terminals, diffs) and don't fit. Its design informed this decision but the protocol itself isn't adopted.

**Open questions surfaced.**
- The exact wire-level message types for the remaining non-MCP primitives; the connector primitive's v0 shape is sketched in `design-notes/connector-primitive-notes.md`.
- How the schema language for entity types interacts with the JSON-RPC envelope (probably JSON Schema, but worth confirming).
- Whether extension discovery is registry-based, filesystem-based, Compose-label-based, or some combination.
- How the host enforces trust tiers in practice (per-extension capability grants, signed manifests, both, neither for v0).

---

## ADR-011: Docker Compose deployment via Coolify; air-gapped Compose for team

**Decision.** Personal deployment is a Docker Compose application managed by Coolify Cloud, targeting a Hetzner host. Team deployment is a Docker Compose application on internal infrastructure, with no public-cloud dependencies. Hypomnema runs as its own Compose service alongside the application, reading from a shared volume populated by Obsidian Sync (or Obsidian Headless Sync in server environments). Extensions in general are added by dropping additional Compose services into the stack; this is the operational shape of the ADR-010 extension model. The Compose definition is kept "Coolify-friendly" — meaning it stays close to patterns Coolify deploys cleanly, with minimal special-casing.

**Rationale.** Coolify already handles TLS, backups, restart policies, and update workflows; leaning on it avoids reinventing the sysadmin layer for a one-user phase. Docker Compose maps cleanly to both deployment targets (personal Coolify, team internal) without per-environment forks. The vault story (Obsidian Sync into a shared volume) keeps Hypomnema's "no install-and-launch-as-server story" limitation off the critical path — sync happens outside the app, and Hypomnema reads from a known location. Self-hosting on a desktop without the full infrastructure remains plausible (a single `docker compose up` should produce a working instance) but is a nice-to-have, not a hard constraint.

**Ruled out.**
- Managed PaaS (Fly, Render, Railway) for personal use. Overbuilt for one user; the team deployment couldn't use it anyway, so the operational practice doesn't transfer.
- Direct VPS without Coolify. More sysadmin overhead than the personal phase warrants.
- Single combined service (host + Hypomnema in one container). Couples deployment to a specific Hypomnema state; harder to update independently; doesn't match the extension topology.
- Public-cloud dependencies in the team deployment. Off-limits by work policy.

**Open questions surfaced.**
- Whether a future "all-in-one desktop install" warrants a separate, simplified Compose file or a single-container build.
- How Obsidian Headless Sync is licensed and managed when running unattended in a Compose service.

---

## ADR-012: Schema-driven entity rendering with an opt-in code escape hatch

**Decision.** Entity rendering on the frontend is driven by a presentation-agnostic schema declared by the extension. Display hints (title field, summary field, icon, type-of-field semantics) are part of the schema but never raw HTML or component code. The frontend has a generic renderer that consumes the schema and produces card / detail / pill views from primitives. Extensions may, optionally, register a custom renderer for a specific entity type as an escape hatch when the generic renderer is insufficient. Native clients fall back to schema-driven rendering for types whose escape-hatch renderer they don't implement.

**Rationale.** Schema-driven rendering preserves native-client optionality — the same schema can be interpreted by web, desktop, or mobile clients — and avoids the "extensions must be in the same language as the host" trap of in-process JS plugins. The opt-in code escape hatch handles the long tail of types that genuinely need custom interaction (calculators, charts, interactive timelines) without forcing every type to be code-based. The three load-bearing seams that make the escape hatch additive rather than a refactor: (a) the schema is presentation-agnostic, (b) the wire protocol carries schema + data rather than pre-rendered content, and (c) the frontend looks up renderers through a registry where the generic renderer is the default entry. Building v0 with just the schema-driven path is the starting point; the escape hatch is added when a real case demands it.

**Ruled out.**
- Dynamic JS loading of extension UI code. Maximum web flexibility, but destroys native portability and introduces a sandboxing and versioning nightmare.
- Server-rendered HTML fragments. Works on web, hostile to native (HTML in SwiftUI is iframe territory).
- Pure schema with no escape hatch ever. Workable, but likely to push extensions toward awkward workarounds when a real case needs custom interaction.

**Open questions surfaced.**
- The exact schema language (JSON Schema is the obvious starting point but doesn't natively express display semantics; some hint layer is needed). *Resolved by ADR-013.*
- The trigger for the escape hatch — when is a type's interaction rich enough to warrant custom code vs. extending the schema language?
- How escape-hatch renderers are distributed (bundled with the extension, fetched separately, signed?).

---

## ADR-013: Schema language for entity types — JSON Schema + presentation hints + routing envelope

**Decision.** An entity type is declared as a three-part **type envelope**: a standard **JSON Schema** describing structure only, a **sibling presentation-hints object** describing display semantics (every value that points at data is a JSON Pointer, never a field name), and a **routing envelope** wrapping both. This is the concrete schema language ADR-012 deferred. The shape was pressure-tested against Hypomnema's `Note`, the host's `Context Set`, and Iris-style memory primitives (`Memory`, `Truth`, `TruthConflict`, `ConversationSummary`) before being locked here.

The envelope carries: `envelope_version` (the version of the schema-language grammar itself), `type` (a dotted `provider.type` identifier, e.g. `hypomnema.note`, so types never collide across providers), `type_version` (semver of *this type's* declaration), `provider` (which provider owns and serves the type), `custom_renderer`, `schema`, and `presentation`. The two version fields are distinct concerns: `envelope_version` tracks the language, `type_version` tracks the dialect a provider speaks in it.

Instances on the wire are **lean** — `{ type, id, data }` — referencing the once-sent declaration by `type` rather than re-transmitting schema and hints with every entity (the ADR-012 "schema + data, not pre-rendered content" commitment, applied to the instance level).

The **presentation hints** are a fixed, authoritative slot list, exercised across both walkthroughs:

| Slot | Value form | Scope |
|---|---|---|
| `title` | JSON Pointer | universal |
| `summary` | JSON Pointer **or** strategy object | universal |
| `icon` | abstract name (`file-text`), namespaceable (`octicons:repo`) | universal |
| `status` | `{ field, variants }` | **typed-meaning providers only** |
| `card_fields` | `[JSON Pointer]` | universal |
| `detail_fields` | `[JSON Pointer]` | universal |
| `references` | `[{ content, resolved_in }]` | universal (empty if no reference-bearing fields) |
| `external_link` | JSON Pointer **or** strategy object | universal, optional |
| `content_types` | `[{ field, type }]` | universal, optional (default: plain text) |

Load-bearing properties of the slot list:
- **Slot values are polymorphic — a JSON Pointer *or* a computed-strategy object** (`{ strategy, source, … }`). Forced by `summary` (Notes have no summary field, so a `{ strategy: "excerpt", source: "/body", max_chars: 200 }` derivation lives in the schema, portable across web/native, rather than in each renderer), and reused by `external_link`.
- **Slots carry a universal vs. typed-meaning-only scope.** `status` asserts a typed lifecycle the provider vouches for; an open-meaning provider like Hypomnema (which refuses to read frontmatter `status:` as an authoritative enum) declares no `status` slot, and renderers show no status pill for its types. The slot exists for providers that *impose* typed meaning (a future `linear.issue`, a typed `ArchitectureDecision`).
- **`content_types` and `references` are orthogonal slots, not one.** `content_types` says *how to render a field's text* (markdown / plain / code); `references` says *which markers in a field resolve to entities, and where the resolutions are*. A plain-text field can be reference-bearing; a markdown field need not be. Collapsing them would force a false coupling.
- **Hints are progressive** — a missing slot falls back to a renderer default; nothing in the hints object is required.

The **`custom_renderer` escape hatch** lives at the envelope level (ADR-012's escape hatch made concrete): `null` means *use the generic schema-driven renderer*, which is the v0 default for every type. A non-null value names a renderer the client may implement; clients that don't implement it fall back to schema-driven rendering, preserving native-client portability.

**Cross-provider references** use a single **universal reference envelope** — the triple `{ provider, type, id, label? }` — wherever one entity points at another. There are *not* separate envelopes for Note references, GitHub repo references, Linear issue references, and so on. `id` is a globally-unique provider URI (e.g. the vault-scoped `hypomnema://localhost/vaults/<uuid>/<path>` form). Type-specific constraints are layered by the *field* that accepts the reference, not by changing the envelope. For body links, a `resolved_reference` (`{ marker, target?, label?, resolved }`) wraps the triple so a **dangling link** can be represented as `{ marker, resolved: false }` with no target — Obsidian parity.

**Query-shaped references** are a distinct membership kind (`kind: "query"`): a stored provider search request plus a `resolves_to` type-intent constraint, resolving to zero-or-more entities at view/serialize time. A query is **not** a standalone `Query` entity in v0 — it has identity only as the member that stores it, and can graduate into a real entity type later if it needs independent lifecycle, sharing, or attachment.

**Icons are an abstraction.** A hint names an *abstract* icon (`file-text`); the host maps abstract names to a concrete library, with **Lucide as the web default**. An extension that needs a specific library uses a **namespaced** form (`octicons:repo`) — the documented escape hatch for domain-specific iconography.

**Rationale.** Keeping hints in a *sibling* object rather than as `x-` keywords inside the schema keeps the JSON Schema a clean, standard, independently-validatable artifact — the schema validates structure, the hints layer display, and neither contaminates the other. The three-part split absorbed two genuinely different types (an open-meaning vault Note with a markdown body and a resolution sidecar; a host-native heterogeneous collection with query-shaped membership) without contortion. One universal reference envelope keeps the entity graph walkable and resolver/renderer dispatch uniform (dispatch through `provider` + `type`, then look up that type's declaration). This decision is the entity-type instance of ADR-003's typed-context-entity / provider-registry pattern (the reference triple is what makes Note→Vault graph-walkable), it resolves ADR-010's open question of how the schema language interacts with the JSON-RPC host↔extension envelope (the type envelope rides inside it), and it is the concrete schema language ADR-012's schema-driven rendering presupposed.

**Ruled out.**
- `x-` keywords inside the JSON Schema. Couples display semantics to structure and forfeits clean, standard validation of the structural artifact.
- Per-provider / per-kind reference envelopes. More surface to maintain and breaks uniform dispatch; the single triple subsumes them.
- A standalone `Query` entity in v0. Query-shaped membership needs no independent identity yet; promoting it now would invent lifecycle and permissions surface before any need exists.
- Inventing a `summary` field on field-less types, or pushing summary/derivation logic into every client. The strategy-object slot form keeps the derivation rule in the schema, portable across clients.

**Open questions surfaced.**
- Temporal-field semantics — `created`/`modified` ride in `card_fields`/`detail_fields` as bare pointers, so a renderer can't tell they deserve relative-time display ("3 days ago"). A per-field `format` hint or a `timestamps` slot would fix it; not adopted yet.
- Field labeling — `card_fields`/`detail_fields` are bare pointers with no human label, which gets awkward for `/frontmatter/some_key`. A `{ field, label }` form may be needed later.
- Whether `resolved_reference` is the universal shape with the plain triple as its degenerate (always-resolved) case, or whether the two stay distinct.
- The canonical `hypomnema://` URI form is provisional — explicit vs. empty authority, the ignored `:name` debug suffix, and remote-host handling all need settling when Hypomnema links are designed.
- Prompt-serialization, expansion-depth, and cache-invalidation policy for resolved query members — deferred to transclusion design (see `design-notes/transclusion-notes.md`).
- Memory lifecycle and recall policy — Iris-style promotion from Memory to Truth, truth crystallization, conflict handling, pinned-truth recall, and prompt section ordering all fit around ADR-013 entities but are not presentation concerns. They should be expressed through extension augmentations / prompt serialization hooks, not schema fields. See `design-notes/memory-primitives-walkthrough.md`.
- Relationship field constraints — the universal reference triple still holds for memory->truth and summary-chain relationships, but schemas need a standard way to declare that a given reference field accepts only selected target types (for example `iris.truth.source_memories[]` accepts `iris.memory`). This may be a schema annotation or sibling hint rather than a new reference envelope.

---

## ADR-014: Operation Registry for internal model orchestration

**Decision.** Internal model-adjacent work is declared through a first-class **Operation Registry**. Each operation has a provider-owned dotted id (`core.chat.respond`, `core.memory.extract`, `hypomnema.context.retrieve`), input/output schemas, model-profile requirements, prompt/rendering strategy, context requirements, scheduling, retry/rate-limit policy, budget, side-effect declaration, and token-accounting policy. The core registers built-in operations. Extensions may register operations during ADR-010 capability negotiation, but only for granted hook points and only after host validation. The host pipeline invokes operations; extensions do not get arbitrary ambient access to host state.

See `design-notes/operation-registry.md` for the declaration envelope and examples.

**Rationale.** ADR-008 commits to multi-model orchestration behind one logical assistant, but "many model calls" needs a control plane before extensions enter the picture. Iris validates the operational need: distinct calls for chat, extraction, summarization, recall, consolidation, embeddings, heartbeat, brief generation, and similar work, each with its own config, job shape, retry behavior, and token usage source. Iris keeps this as an in-process convention — config key + service + queued job + `TokenUsageSource` enum — which is fine at one-developer scale but does not cross an extension boundary cleanly.

The registry keeps the useful parts: named operations, per-operation model choice, explicit retry/rate-limit behavior, and per-operation token accounting. It changes the registration dimension: operations become declared capabilities the host can validate, compose, schedule, observe, and revoke. Model selection uses host-owned model profiles (`chat.frontier`, `reasoning.medium`, `reasoning.fast`, `embedding.text`) that tenant/admin policy resolves to concrete provider/model/version values, so declarations stay stable while model choices evolve.

**Ruled out.**
- Iris-style ad-hoc convention as the core design. Works in-process, but makes extension-owned operations, host policy enforcement, and uniform accounting too implicit.
- Full agent graph as the base abstraction. Useful later for autonomous work, but too heavy for ordinary pipeline steps like thread naming or memory extraction.
- A tiny model-call helper keyed only by operation name. Captures model selection but misses retry, scheduling, context grants, side effects, and token accounting — the parts that matter operationally.
- Letting extensions choose concrete models directly. Breaks tenant policy, cost control, and future provider migration. Extensions request capabilities/model profiles; the host resolves them.

**Open questions surfaced.**
- The exact v0 hook-point vocabulary (`turn.completed`, `context.retrieve`, `response.generate`, `suggestions.rank`, etc.).
- Whether long-running agent work (`core.subagent.run`) stays in this registry with a different scheduling mode or graduates to a separate agent-run primitive.
- How much of the operation registry is exposed in admin/debug UI at v0 versus only recorded in logs and usage tables.

---

## ADR-015: Skills are content packages, not a first-class extension primitive

**Decision.** Skills (Anthropic/skills.sh-style `SKILL.md` packages) are treated as content packages that the host catalogs and pins to threads, not as a new peer primitive alongside entity declarations, prompts, tools, connectors, or operations. Extensions may ship skill packages. The host parses and validates their metadata, records source/provider/version/hash, controls visibility and trust, exposes available skills through host-owned prompt declarations, and injects pinned skill content through the normal prompt assembly path. Activating a skill creates a thread-scoped pin to a skill package/version.

See `design-notes/skills-content-extensions.md` for the catalog shape and multi-user implications.

**Rationale.** Iris validates the simpler branch: skills are loaded from `SKILL.md` files, advertised in a prompt, activated via a tool, pinned by thread, and injected into future system context. That works because skills are instructions plus metadata; their behavior is carried by existing tools, prompts, and thread state. Making skills a full extension primitive would duplicate ADR-010 machinery without adding a distinct runtime capability.

The part that does not generalize from Iris is an unscoped global filesystem scan. For multi-user, skills need host-owned cataloging with source scope, visibility, version, source URI, and content hash. A configured local directory is acceptable for the personal v0, but it should feed the same catalog shape that extension-declared skills use later.

**Ruled out.**
- A remote skill marketplace/registry in v0. Distribution, signing, reviews, and update policy are premature; a host-local catalog is enough.
- Treating skills as MCP tools. A skill may mention allowed or expected tools, but tool grants remain host policy.
- Treating skills as context entities. They are not domain objects the user is grounding a conversation in; they are instruction packages that affect model behavior.

**Open questions surfaced.**
- Exact skill id/version semantics when a local `SKILL.md` changes after being pinned to an existing thread.
- Whether user-authored ad-hoc skills need an editing UI or can stay filesystem/config driven through v0.
- How pinned skill content participates in cache-breakpoint allocation once prompt assembly budgets are implemented.

---

## ADR-016: Context Budget Planner for prompt admission and compaction

**Decision.** Every model request is assembled through a host-owned **Context Budget Planner**. The planner starts from the operation's context window and reserved completion/headroom, accounts for fixed prompt/tool/schema costs, then admits conversation history, summaries, attached entities, transclusions, and retrieved context by classed budget policy. Prompt renderers and extensions receive explicit per-render budgets; they do not decide the global prompt shape.

See `design-notes/context-window-management.md` for the budget plan shape, admission order, pruning modes, entity render modes, compaction triggers, and safety-net behavior.

The planner treats context as degradable before it is droppable. Recent turns are protected from aggressive pruning but still capped per item. Older turns degrade to tool previews or summaries. Attached entities degrade from `full` to `summary` to `reference` or `fetchable` rather than being blindly injected. Query-shaped Context Set members and transcluded references have their own result-count, depth, and token caps.

Conversation compaction is an operation (`core.conversation.summarize`) run asynchronously after turns when token pressure or unsummarized-turn accumulation crosses policy thresholds. The request path has a bounded synchronous safety net: if budgeted history loading would exclude too much unsummarized material, it may run one locked, timed compaction attempt before sending the chat request. If that attempt fails, the request continues with degraded context and an omission record rather than blocking indefinitely.

**Rationale.** Context-window pressure is a first-class design concern in this product because context is not only transcript text. Typed entities, Context Sets, query results, transcluded notes, tool output, prompt declarations, and provider schemas all compete for the same model window. A central planner is the only place with enough visibility to make coherent tradeoffs.

Iris validates the operational pattern: token budgeting, bounded history loading, protected recent turns, heavier pruning for older tool output, async summarization, and a sync safety net when summarization falls behind. This design adopts the pattern but changes the object being budgeted. Instead of only chat rows and tool output, the planner budgets typed entities and extension-rendered prompts as first-class participants.

The planner also creates debuggability. Each request can record what was admitted, degraded, omitted, estimated, measured, and summarized. That makes "the assistant forgot this" diagnosable as either model behavior or prompt-admission policy.

**Ruled out.**
- Directly injecting all attached context. Fails as soon as a user pins a large note, Context Set, or conversation export.
- Oldest-history-until-budget-exhausted. Preserves stale transcript at the expense of explicit attachments and current task context.
- Summarization-only compaction. Summaries help, but tool output, entity expansion, query results, and transclusion need separate pruning/degradation policies.
- Renderer-owned budgeting. Extensions can estimate and respect local budgets, but only the host can coordinate across prompts, tools, history, and entities.
- Dropping oldest material silently. Sometimes necessary, but omissions must be recorded and, where useful, surfaced to the model.

**Open questions surfaced.**
- Default token allocation ratios for v0 need real prompts and provider windows.
- The exact `context.fetch` tool/operation ergonomics for fetchable large entities remain to be designed.
- Transclusion still needs its own ADR for depth, shape, and cache invalidation; ADR-016 only fixes how transcluded content participates in the budget.

---

## ADR-017: Read/write artifacts via a per-type capability surface

**Decision.** Entity types may declare a **capability surface** — which write operations a conversation may run against them — through a new `capabilities` field in the ADR-013 type envelope. The field is a map keyed by operation (`create`, `update`, `delete`, `rename`), each value a small contract object; an absent operation is unsupported, and an absent `capabilities` field means **read-only** (every type declared before this ADR). Adding this top-level routing field bumps `envelope_version` to `"1"`, additively — a `"0"` envelope is interpreted as read-only, so existing declarations keep working unchanged. Capability is per-type, per-provider. The core ships **one** baseline artifact-capable type, `core.document`, host-Postgres-backed with a host-minted UUID identity, so a zero-extension install can still create and edit. Extensions advertise their own types as artifact-capable when configured for it (e.g. a write-enabled `hypomnema.note` whose create writes into a designated vault outputs-folder).

Three layers stay separate and own distinct questions: **ADR-013** declares the *capability surface* (which operations, and their contract); **ADR-014** *executes* — a declared capability is synthesized by the host into a registered write operation, side-effect-classed `external_write`, with the type's provider as executor and an input schema derived from the operation's `writable_fields`; **ADR-010** carries the *wire* and reports **runtime availability** at capability negotiation. Capability surface is versioned and stable (it rides `type_version`); availability is per-deployment and runtime (a Hypomnema pointed at a read-only vault declares create but reports it not-live). A successful write returns a lean instance that the host emits as a **citable entity event into the thread** (ADR-004), so what a conversation creates is immediately a first-class, pinnable, referenceable entity in the same conversation.

See `design-notes/artifact-capabilities-walkthrough.md` for the `capabilities` shape, the `core.document` and write-enabled `hypomnema.note` declarations, the write-execution path, target/picker semantics, and the versioning/undo posture.

**Rationale.** The motivating pain is the workflow gap between Claude.ai's read-only project files (edit locally, delete, reupload) and Claude Code's "needs a repo" feel — one of the explicit problems this system exists to solve. Everything ADR-013 declared was read-facing; closing the gap means a write surface that doesn't turn the clean read model into a storage engine or commit the core to one provider's storage choices. The capability-on-the-type / execute-via-operation / negotiate-availability split keeps the type declaration stable while letting a deployment's config decide what's actually writable — resolving the tension that artifact capability is "a runtime property tied to config, not a static fact about the type." `writable_fields` as JSON Pointers reuses ADR-013's field-reference discipline and keeps provider-derived fields (resolved references, timestamps, vault-scoped identity) out of the conversation's hands. `rename` is a separate operation from `update` because it rewrites identity and carries reference-integrity consequences update never does. A baseline `core.document` honors the personal-first, no-capability-less-cold-start commitment (ADR-006) without forcing the storage abstraction (S3/NFS/document DBs) up front — Postgres blob plus a per-artifact mutation log covers the near-term and yields versioning/undo for free. This decision is the write-side instance of ADR-003's typed-entity / provider-registry pattern and ADR-010's host-mediated surface, layered onto the ADR-013 envelope it extends.

**Ruled out.**
- Baking general read/write storage into the core as the *whole* story (handoff Option 1). Commits the core to versioning/backup/storage-backend choices and overlaps extension-provided typed storage like Hypomnema. The baseline `core.document` is the minimal version of this — one type, not a storage platform.
- Pure "extensions advertise capability, core has none" (handoff Option 2). Leaves a fresh, zero-extension install with no write capability at all — a capability-less cold start. The baseline `core.document` fixes exactly this.
- A flat capability list (`["create","update"]`). Doesn't survive the first concrete type: create and update have different writable sets, delete has a soft/hard mode, rename rewrites identity. The operations aren't uniform flags; each needs its own contract object.
- Putting runtime availability in the versioned envelope. Would make the type declaration churn with every config change; availability belongs in ADR-010 negotiation, contract in the envelope.
- A separate second-class "artifact" representation distinct from read entities. Writes emit the same lean-instance envelope reads consume, so created things are immediately citable — the property read-only project files can't offer.

**Open questions surfaced.**
- Optimistic-concurrency depth: the exact `version_token` (etag / mutation-log sequence / `modified` timestamp) and the edit-conflict UX, unneeded at single-user v0 (ADR-006) but required before multi-user editing.
- Confirmation/autonomy policy for `external_write` operations — where the gate that requires confirmation before the model writes unattended lives (side-effect-class policy in ADR-014, per-operation, or per-target).
- The artifact-target augmentation kind (a Context Set supplying a default create-target) depends on the still-open augmentation-surface registration shape; see `design-notes/extension-augmentation-notes.md`.
- Canonical `core://` URI form (authority, tenant-scoping, collection-path encoding), provisional alongside the other host-native ids.
- Bulk/multi-file artifacts (writing several linked notes or a folder transactionally) — out of scope here; every operation is single-instance.
- Rename reference-rewriting for providers that don't self-heal the way Obsidian rewrites wiki-links; cross-type "save as" overlap with the cross-provider type-overlay question.

---

## ADR-018: Prompt Declarations — host-planned assembly with declared ordering and centralized cache placement

**Decision.** System-prompt producers register a **Prompt Declaration** once — a routing envelope (`envelope_version`, `kind: "prompt"`, `prompt`, `prompt_version`, `provider`, `renderer`), a JSON Schema describing the request data the renderer consumes, and assembly hints (`context_requirements`, `output`, `ordering`, `cache`) — and the host calls each selected renderer per request with a lean payload (`prompt`, `prompt_version`, `request_context`, `budget`) that returns zero or more rendered system messages plus a usage estimate. Declarations are exchanged at ADR-010 handshake or capability refresh, never re-sent per request; renderers may live in-process (`renderer.transport: "host"`) for core prompts or over JSON-RPC for extension prompts. This is the prompt-primitive analogue of ADR-013's "declaration once, lean instance on the wire" pattern — not a new entity-envelope kind, but the same separation applied to prompt assembly.

Three host-owned concerns stay centralized and are explicitly *not* the prompt's to decide. **Ordering**: a mergeable model of named ordered slots (`identity.static`, `behavior.static`, `capabilities.static`, `context.thread`, `context.dynamic`, `temporal.dynamic`, `final.dynamic`), each declaration carrying a slot, a numeric `weight`, and optional `before`/`after` anchors by prompt id; the host merges declarations by slot order → weight → anchors (cyclic rejected) → provider+prompt id tiebreaker, and user/admin config may disable, re-weight, or pin within allowed slots, but a third-party extension cannot quietly move dynamic content ahead of stable identity or behavior. **Cache placement**: a single host policy layer applies cache breakpoints to the final ordered message list, grouping ordered prompt ids by declared `cache.preferred_group` (e.g. `static_prefix`, `thread_context`), applying the breakpoint to the last non-empty message in each group, consuming a breakpoint only when the group has rendered content, and capping at the provider's `max_breakpoints`; provider-specific cache controls returned by a renderer are stripped unless the host has an explicit adapter. **Context grants**: the per-request `request_context` is host-supplied as snapshot data, universal references (ADR-003 provider/type/id), host-owned handles for larger or sensitive data, or omitted — declared `context_requirements` express need; the host decides the concrete subset granted per request, so a renderer cannot impersonate host state.

See `design-notes/prompt-declaration-walkthrough.md` for the envelope shape, the render request/response, the `RequestContext` equivalent, the v0 slot list and merge rules, the cache-group policy, worked core declarations (`core.autonomous_execution`, `core.current_time`, `core.pinned_prompts`), and the extension-handshake registration shape.

**Rationale.** Iris validated the useful split — central registration, lean per-request rendering, a `RequestContext` bag, and a single authority for cache placement — but did so in-process, so ordering was array order in a PHP config and grants were ambient. Carrying the split across ADR-010's process boundary forces every implicit decision into the declaration: order has to be mergeable (slot + weight + anchors, not array index), context has to be granted (snapshot/reference/handle/omitted, not "whatever is on the request object"), and cache placement has to be a host-side layer over the merged plan (groups by prompt id, not provider-local message indexes), because a single extension cannot see the other extensions' contributions and must not impersonate host state. The envelope plus assembly-hints shape mirrors ADR-013 deliberately: declarations are cheap to validate, cache, and reason about precisely because they are separated from per-request instances, and JSON Schema works for the request *structure* while a small assembly-hints vocabulary (`context_requirements`, `output`, `ordering`, `cache`) carries the meaning JSON Schema can't. The slot list — identity → behavior → capabilities → thread context → dynamic context → temporal → final — is the v0 vocabulary that lets the host enforce "stable prefix first, volatile content last" without legislating every prompt's exact position. Cache groups on ordered prompt ids generalize Iris's single-authority rule across providers: a first-party extension prompt can join `static_prefix`, but a vault-context prompt whose stability is `per_thread` cannot, and the host enforces the provider cap globally. This decision is the prompt-side instance of ADR-003's provider-registry pattern and ADR-010's host-mediated surface, and it cooperates directly with ADR-016: the budget planner decides *what* is admitted; the Prompt Declaration layer decides *how* what is admitted gets ordered, rendered, and cache-marked.

**Ruled out.**
- Letting each prompt own its cache controls (mirror Iris's "single authority" away). Across the ADR-010 boundary, per-prompt cache placement loses any global picture of group membership and provider caps; an extension setting `cache_control` on its own message would race other extensions' placements and routinely overshoot `max_breakpoints`.
- Array-order ordering (Iris's `config/iris.php` shape) carried across the wire. Survives one provider; fragile across many because there is no merge rule and no protection against a third-party extension declaring itself first.
- Allowing a third-party extension to place a prompt into `identity.static` or `behavior.static` without explicit operator trust. Stable cacheable prefix is exactly the property a malicious or careless extension could destroy by pushing volatile content there; slot pinning into trusted slots is operator-policy, not extension-policy.
- A schema rich enough to bake `RequestContext` shape (user, thread, attached entities, recalled context) directly into every prompt declaration. Mixes the declaration vocabulary with host-internal state and makes every host change a breaking schema change; `context_requirements` + host-granted snapshots/references/handles keeps the contract narrow.
- Letting extensions return provider-specific cache fields (Anthropic `cache_control`, etc.) directly. Bypasses the host's `max_breakpoints` cap and ties the prompt to one provider's vocabulary; the host strips them unless an explicit provider adapter is configured.
- Re-sending declarations with every render call. The declaration is the cacheable artifact; per-request payloads are deliberately lean (`prompt`, `prompt_version`, `request_context`, `budget`) so the wire stays cheap and the registration is the audit point.
- A separate "prompt envelope kind" parallel to ADR-013's entity envelope. The pattern is shared, but prompts are not rendered entities — their hints are assembly hints (ordering, cache eligibility, empty behavior), not presentation hints. Same shape, distinct vocabulary, same registration surface (ADR-010) and same universal-reference bridge for inputs.

**Open questions surfaced.**
- **Context-grant vocabulary.** `context_requirements` needs a real vocabulary for sensitivity (`user_input`, `conversation_metadata`, `entity_reference`, `preference`, …), scope (`per_turn`, `per_thread`, `per_user`, `per_deployment`), and handle resolution (which host methods a granted handle authorizes) before this is implementable end-to-end.
- **Render-failure semantics.** Static required prompts (`empty_behavior: "error"`) must fail request construction when rendering fails; optional prompts (`empty_behavior: "omit"`) must degrade with an observable omitted-prompt event. The exact event shape, retry policy, and timeout treatment relative to ADR-014's operation-execution model are unresolved.
- **Token-budget reporting.** Renderers need a standard contract for reporting truncation, omitted sections, and estimated token count back to the planner (ADR-016) so the planner can attribute overruns and decide degradations on the next turn.
- **Provider-specific cache adapters.** The host policy is provider-neutral, but Anthropic's `cache_control` is the concrete v0 target; other providers need their own adapter contracts (and some have no equivalent), to be defined alongside the model-router work.
- **Capability-type wrapper at the extension boundary.** `kind: "prompt"` works in isolation, but ADR-010 will likely want a top-level `capability_type` wrapper so entity declarations (ADR-013), prompt declarations (this ADR), operations (ADR-014), and hooks share one registration envelope.
- **User-visible ordering controls.** Admin config can override slot, weight, and disable/enable; the UX for end-users pinning or re-ordering their own prompts (and the scope at which those overrides apply) is deferred.
-
- ## ADR-019: `symfony/ai` Platform as the model abstraction

**Decision.** The core uses **`symfony/ai`** (the Platform component, wired through the AI
Bundle) as its model-client abstraction. Application code talks to the model through the
Platform's vendor-neutral interface and never hard-codes a provider; concrete
provider/model/version is late-bound by config via provider bridges. A thin host-owned
**model-profile resolver** sits on top, mapping ADR-014 profiles (`chat.frontier`,
`reasoning.medium`, `reasoning.fast`, `embedding.text`) to a Platform + model selection.
Scope is deliberately narrow: `symfony/ai` is adopted for **model calls only** (text
generation, streaming, embeddings, structured output, tool calling). Its Chat component is
**not** adopted — conversation persistence stays host-owned and event-sourced (ADR-004).
Its Store (vector/RAG) and Agent (orchestration) components are deferred, not committed.

**Rationale.** ADR-008 already commits provider/model to being a backend concern, and
ADR-014 specifies that operations request a *profile* the host resolves to
provider/model/version at runtime. Those decisions describe an abstraction the project would
otherwise have to hand-roll across every provider — request shaping, streaming
normalization, token accounting, structured-output coercion, tool-call plumbing. `symfony/ai`
provides exactly that abstraction natively for the Symfony core (ADR-007): a single
`PlatformInterface` with provider bridges, switchable by config without touching application
code, and it already covers streaming, token usage, structured output, and tool calling.
Adopting it follows the same instinct as ADR-009 (assistant-ui) — don't reinvent the
boring-but-load-bearing layer when a well-shaped library owns it.

The convergence/divergence read is clean. Iris reaches for a library here too (Prism PHP,
`prism-php/prism`) rather than hand-rolling — convergence that validates "use a library."
Where this design diverges is forced, not philosophical: Prism is Laravel-coupled (container,
facades) and doesn't fit a Symfony core, so the Symfony-native equivalent is the right port
of the same pattern. The library being vendor-neutral also de-risks the model-router work
ADR-018 presupposes: `FailoverPlatform`, OpenRouter, and multi-provider support are things
`symfony/ai` is itself building toward, rather than infrastructure this project must own.

This is the substrate the Phase 0.3 turn loop builds its first profile resolver on, and the
foundation the eventual tenant/admin-policy model router (ADR-014/018) grows from.

**Ruled out.**
- **Hand-rolled per-provider HTTP clients.** Maximum control, but reinvents bridges,
  streaming normalization, token accounting, and structured-output handling — and the
  maintenance cost scales with every provider added. The control isn't worth the surface.
- **Prism PHP.** Iris's choice and a proven same-author ecosystem, but Laravel-coupled;
  wrong framework fit for a Symfony core (ADR-007). The pattern is kept, the package isn't.
- **Other PHP LLM libraries (e.g. LLPhant).** Viable, but less Symfony-native; `symfony/ai`
  integrates through the bundle/DI/config path the rest of the core already uses.
- **`symfony/ai` Chat component for conversation history.** Collides with the
  event-sourced conversation model (ADR-004); persistence and the event log are host-owned
  and canonical. Only Platform is in scope.
- **`symfony/ai` Agent component as the orchestration layer (now).** Conceptually overlaps
  ADR-014's Operation Registry; committing to it now would pre-empt that design. Revisit as
  a possible implementation detail behind the registry, not as the registry.

**Open questions surfaced.**
- **Experimental-dependency risk.** `symfony/ai` is pre-1.0 and explicitly outside Symfony's
  BC promise. Version is pinned and upgrades are deliberate — but is a thin anti-corruption
  wrapper around `PlatformInterface` warranted to insulate the core from churn, or does the
  profile resolver already provide enough of a seam?
- **v0 default bridge.** Which provider bridge backs `chat.frontier` first — an
  OpenAI-compatible bridge (most portable, and the lingua franca for local backends), an
  Anthropic bridge, or a key-free local bridge (Ollama) for dev. A 0.0 sub-decision, but the
  standing default is unsettled.
- **Prompt cache breakpoints through the abstraction.** ADR-018's cache-breakpoint strategy
  targets Anthropic's `cache_control` first. Whether that provider-specific control is even
  expressible through the Platform abstraction (or normalized away by it) determines whether
  the ADR-018 "provider-specific cache adapters" live above or below `symfony/ai`.
- **Embeddings location.** Platform also abstracts embeddings. Does that change the open
  "embedding pipeline location" calculus (in-core pgvector vs. Python extension), and does it
  pull the Store component back into scope when in-core embeddings land?
- **Tool calling vs. ADR-010 MCP.** `symfony/ai` supports tool calling and ships an MCP
  Bundle; ADR-010 already adopts MCP for tool-shaped capabilities. Whether these align (the
  bundle becomes the MCP client/server substrate) or stay separate is worth settling before
  tools are built.

---

## ADR-020: v0 authentication — console-minted users, password form login

**Decision.** v0 has no web-facing authentication surface beyond a login form. Users are
**minted via `bin/console app:user:create`** (which also attaches an owner `Membership` to
a named `Tenant`). The web app authenticates with **Symfony Security `form_login`** over a
password hash stored on `users.password_hash`, session-based, single firewall, single role
(`ROLE_USER`). There is no registration UI, no password reset, no email verification, no
remember-me, and no OIDC/SSO. Identity is **UUIDv7** (time-ordered, matching Hypomnema's
choice), surfaced at the application boundary as **`core://users/{uuid}`**. The user ↔
tenant relation is modeled as a `Membership(user, tenant, role)` join even though v0 is
exactly one user, one tenant — directly applying ADR-005's "permissions modeled even if
all owner" so a future second user or tenant is a row insert, not a schema redesign.

**Rationale.** The long-running open question — "OIDC from day one, or password-first
with SSO later?" — assumed there *was* a self-service auth surface to design. The
console-minted approach dissolves the question for v0: with no public registration and
exactly one operator, password-form-login is the cheapest thing that meets the bar, and
adding OIDC later is additive (a new authenticator, not a migration of identity data).
ADR-010's host-mediated extension model keeps the door open for an auth provider that
plugs in over JSON-RPC once the abstraction is right, but that conversation belongs to
the multi-user phase. UUIDv7 over autoincrement keeps the id format consistent across the
typed-entity providers ADR-013 will register and lets references quote a stable url-safe
identifier from the first row. The `Membership` join is the load-bearing piece: every
downstream table inherits `tenant_id` from this pattern, and v0 not having that join is
the kind of "single user now, refactor later" choice ADR-005 already ruled out.

**Ruled out.**
- *OIDC/SSO from day one (Clerk/Auth0/Supabase Auth or self-hosted Keycloak/Hydra).*
  Lots of integration surface for a single operator with no team; v0 lacks the multi-user
  problem these solve. Reachable later as an additive authenticator or as an extension.
- *No auth at all behind a private network.* Even single-user, the artifact-write surface
  (ADR-017) makes "anything reachable can write" a worse failure mode than a login form.
- *Folding tenant identity into `users` (no `Membership`).* Cheapest now, most expensive
  later — exactly the trap ADR-005 calls out. The empty owner column costs nothing.
- *Autoincrement integer ids.* Cheap, but burns the alignment with the typed-entity
  registry's URL-safe identifier shape and forces a later id-format migration.
- *Symfony's `make:user` / `make:auth` scaffolding without explicit `core://` surfacing.*
  The generators produce a working login but bury the URI shape this system's references
  depend on; minting it ourselves keeps the surface deliberate.

**Open questions surfaced.**
- *OIDC adoption path.* When multi-user lands, does OIDC arrive as a second Symfony
  authenticator alongside form-login, as an auth provider extension over ADR-010, or as a
  full replacement that retires password login? Likely the first or second; deferred.
- *Per-extension capability grants.* ADR-010 mentions trust tiers; v0's "everything is
  ROLE_USER" is the placeholder. The shape of capability grants (per-extension manifests?
  signed?) is left to the multi-user / multi-extension phase.
- *Account recovery without email.* If the console-minted operator loses their password,
  the recovery path is "re-run `app:user:create`" — fine for one operator; not a strategy
  for multi-user.

---

## ADR-021: `Tenant` terminology and shape for v0 (account = tenant = workspace)

**Decision.** v0 collapses **account**, **tenant**, and **workspace** into a single
`Tenant` entity in its own `tenants` table. The schema and code say `Tenant`; UI copy
may say "account" or "workspace" where that reads better. Tenant identity is **UUIDv7**
surfaced as **`core://tenants/{uuid}`** — the URI form already used by the
operation-registry and request-context shapes. Future split paths — `tenant` becoming an
org/billing boundary that contains one or more `workspace` containers — remain a
migration (add a `workspaces` table, point membership at workspace, leave `tenant` for
billing) rather than a redesign. Every later table inherits tenancy by carrying a
`tenant_id` from this pattern.

**Rationale.** The design docs use "tenant" / "workspace" interchangeably; the
event-table reference uses `workspace_id`; the informal framing uses "account." Picking
one name for v0 — and only one row — prevents the trap where a half-built schema has
both `tenant_id` and `workspace_id` columns enforcing nothing. Putting it in its own
table (instead of folding it into `users` or `memberships`) makes the future split a
schema migration on a known table, not a redesign of the join shape. The `core://`
URI form was already provisional from ADR-017's "canonical `core://` URI form" open
question; settling it for tenant + user here gives every downstream URI kind
(threads, messages, documents) a precedent to follow rather than re-litigating the
shape per table.

**Ruled out.**
- *Three separate tables now (`accounts`, `tenants`, `workspaces`).* No row would
  meaningfully differ across them in v0; the shape adds JOINs and ambiguity without
  buying anything until multi-tenant + multi-workspace concerns are real.
- *Inline tenant on `users` (no `tenants` table).* Saves one table; loses the column
  to hang every downstream `tenant_id` off, and makes the future tenant/workspace
  split a much harder refactor.
- *`Workspace` as the v0 noun.* Considered for alignment with the event table's
  `workspace_id` column, but "tenant" reads better in security/membership context and
  the downstream rename is mechanical. UI copy stays flexible.
- *Skipping `Membership` and joining `users → tenants` directly.* Same trap as ADR-020's
  "fold tenant into users" — see there.

**Open questions surfaced.**
- *Tenant vs. workspace split criteria.* Which axis triggers the split — first paying
  customer (billing boundary), first multi-workspace user, or first cross-workspace
  share? Deferred until any of those is actually on the roadmap.
- *Tenant slug semantics.* v0 treats `slug` as a human-typed kebab identifier with a
  shape constraint. Whether it becomes URL-routable (`/{tenant}/...`) or stays
  console-only is a step-5 routing decision.
- *Canonical `core://` URI form for other entity kinds.* This ADR settles tenants and
  users; threads/messages/documents inherit the shape but ADR-017's broader question
  (authority, tenant-scoping, collection-path encoding) is still open across the
  registry as a whole.
