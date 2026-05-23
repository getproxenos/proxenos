# Architecture Decisions

Lightweight ADRs. Each entry: the decision, the rationale in a few sentences, and the main alternatives ruled out. The point is to head off "have you considered X?" detours in design conversations where X has already been considered.

Format is intentionally loose. Date entries when they're adopted; mark superseded rather than deleting if a decision changes.

---

## ADR-001: Server-authoritative state with resumable streaming

**Decision.** The server is the source of truth for thread and message state. Assistant message generation writes to durable storage as tokens stream, not just to the websocket. Clients are subscribers, not owners. Disconnects do not lose responses; reconnects resume from a cursor.

**Rationale.** This is the dominant pattern across serious AI chat products (ChatGPT, Claude.ai, modern Vercel AI SDK patterns) because it solves several problems at once: offline tolerance, multi-device sync, background generation, parallel monitoring. The opposite pattern — client-authoritative state, server-streams-to-websocket-and-forgets — is what Iris does today and is the direct source of the persistence bugs motivating this work.

**Ruled out.**
- Client-persisted state (Iris's current model). Fails on disconnect; blocks multi-device.
- Server-state-without-resumability. Cheaper to build but loses the "ask a question, walk away, come back to the answer" UX, which is one of the main motivating use cases.

---

## ADR-002: ExternalStoreRuntime as the frontend integration shape

**Decision.** The frontend uses assistant-ui's `ExternalStoreRuntime` pattern, where message state lives in a store outside the runtime and the runtime is a view onto it. The store is populated by a server subscription (websocket or SSE).

**Rationale.** This is the runtime shape that lets server-authoritative state flow naturally. `LocalRuntime` would put the frontend back in charge of state, defeating the point. `DataStream` would tie the backend to the Vercel AI SDK wire format. `ExternalStoreRuntime` imposes no wire protocol — the backend can be anything that can populate the store. That freedom matters given the open backend-language question.

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

**Decision.** Thread state is persisted as an ordered event log per thread. Events have monotonic sequence numbers per thread. Clients subscribe by cursor; reconnect by cursor. Current message state is a fold over the event log.

**Rationale.** The events streamed to clients and the events persisted to the database are the same events. This unifies resumability and audit-trail concerns into one design instead of two. Familiar territory given prior event-sourcing experience. Implementation can start with Postgres plus `LISTEN/NOTIFY` (or simple polling) and graduate to Redis Streams later if fan-out demands it.

**Ruled out.**
- Snapshot-only persistence. Loses the streaming reconstruction story.
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

**Rationale.** assistant-ui solves the boring-but-load-bearing chat UI problems (streaming, virtualized message lists, attachments, branching, code blocks, accessibility) that are not worth reinventing. The runtime abstraction is the right shape (see ADR-002). Composability with shadcn means UI design stays unconstrained.

**Ruled out.**
- Building chat primitives from scratch. Months of work for no differentiation.
- Vercel AI SDK + AI Elements as the whole frontend story. Pushes toward DataStream runtime, which we've ruled out (ADR-002).
- LibreChat / Open WebUI as a base. Wrong shape — those are products, not libraries.

---

## ADR-010: Host-mediated extension surface

**Decision.** Extensions interact with the host through a defined set of primitive APIs — entity type declarations (extending ADR-003), suggestion proposals, pipeline lifecycle subscriptions, UI affordance requests, and tool exposures via MCP. Extensions never directly manipulate UI components, host internal state, or other extensions. The host owns all control surfaces; extensions declare intent, and the host decides how that intent surfaces. Extensions run as separate processes (typically additional Docker Compose services) and communicate with the host over a JSON-RPC-shaped protocol with capability negotiation and explicit protocol versioning from v0.

**Rationale.** The pattern is well-validated by MCP, LSP, DAP, and ACP — all "host ↔ peer" protocols in the same family. Host-mediated primitives give us: language-agnostic extensions (subprocess + JSON-RPC), forward and backward compatibility (capability negotiation), trust tiers (the host controls which primitives are exposed to which extensions), native-client portability (any frontend can implement the same host primitives), and a stable extension contract that survives host internal changes. This generalizes ADR-003's provider pattern across the broader extension surface and concretizes the "extensible without forking" commitment that has motivated the architecture from the start.

MCP is adopted specifically for tool-shaped capabilities (tools, resources, prompts). The broader extension primitives — entity declarations, suggestions, pipeline hooks, UI affordances — layer alongside MCP using the same wire shape (JSON-RPC over a transport) with additional message types. The boundary between "what's MCP" and "what's our own primitives" is functional: MCP where it fits, our own where it doesn't.

**Ruled out.**
- In-host plugin model (extensions loaded into the host's process). Couples extensions to host internals; defeats native-client portability; makes trust tiering hard; recreates exactly the Iris pain this project is trying to escape.
- Per-surface extension APIs (a separate "suggestion plugin" API, a separate "UI plugin" API, and so on). More surface area to maintain; loses the unifying pattern.
- ACP as the wire protocol. Its primitives are editor-shaped (files, terminals, diffs) and don't fit. Its design informed this decision but the protocol itself isn't adopted.

**Open questions surfaced.**
- The exact wire-level message types for the non-MCP primitives.
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
- The exact schema language (JSON Schema is the obvious starting point but doesn't natively express display semantics; some hint layer is needed).
- The trigger for the escape hatch — when is a type's interaction rich enough to warrant custom code vs. extending the schema language?
- How escape-hatch renderers are distributed (bundled with the extension, fetched separately, signed?).
