# Project Overview

## What this is

A personal-first, multi-user-eventually AI workspace built around **typed, pluggable context**. The premise: most AI tools fall into one of two camps — generic chat with no structured grounding, or blob-RAG over a pile of documents. The piece that's missing is treating the entities you actually work with (products and parts, notes and people, conversations and code) as first-class context items: attachable to threads, drillable into, suggestible based on what you're talking about, and explicitly referenceable in conversation.

The shape is closest to what the industry has started calling "vertical AI" or "domain-grounded AI," but with three specific commitments that distinguish it:

1. **Open extension surface.** Adding a new kind of context entity, a new tool the model can call, a new step in the response pipeline, or a new UI affordance is a first-class extension point — not a fork. Extensions run as separate processes and interact with the host through declared primitives (see ADR-010).
2. **Server-authoritative state.** The frontend is a view, not a source of truth. Conversations survive disconnects, work across devices, and aren't held hostage by the client being open.
3. **Client-agnostic by design.** The wire protocol between host and client is general, not React-specific. Web is the first client; native desktop or mobile clients can be added without rebuilding the backend.

The build is starting personal so I can use my own structured data (Obsidian notes via Hypomnema, exported Claude.ai conversations) as the first context providers. Multi-user comes once the abstraction has been pressure-tested by daily use.

## Why now

Two motivating gaps:

1. **Iris is the closest existing thing to what I want, but it's a commercial codebase I don't own and can't iterate on.** My patches for environment-driven model configuration haven't been accepted upstream, and the extension model — where adding new context types or tools requires editing the application source — would be problematic even with full write access. The architectural shape works; what's missing is an extension surface that doesn't require forking. That's the specific pain that motivates the extensibility commitment in this project.
2. **The open ecosystem is thin in exactly this corner.** Open WebUI and AnythingLLM cover generic chat well. LibreChat and LobeChat cover multi-provider chat. Glean covers enterprise-search-with-chat (commercial only). Nobody combines typed-entity grounding, collaborative use, persistent memory, an extensibility model that doesn't require forking, and a polished UI in something I can self-host.

## Who it's for

In priority order:

1. **Me, daily.** Personal use against my Obsidian vault and Claude history.
2. **My team at work.** A small group with shared domain entities (products, parts, customers, projects) and a need for grounded chat that respects those entities. Internal-only deployment, no public cloud.
3. **Wider, eventually.** If it survives (1) and (2), it could become something other people self-host.

Anything beyond (2) is aspirational and should not influence near-term decisions.

## Glossary

Named things that come up repeatedly. Define once; use consistently.

- **Iris** — a commercial AI chat product by TJ Miller, licensed via GitHub Sponsorship, that I run for personal use. Iris's architecture has informed my thinking through study (which the license explicitly permits as educational use), but I cannot reshape Iris's codebase, and any work I do is independent. This project replaces Iris in my workflow as a clean, independent rebuild; coexisting with it (Iris as an upstream context source) was ruled out, and evolution from Iris was never available. See `open-questions.md`.
- **The 14 operations** — Iris's pattern of orchestrating distinct model calls for distinct sub-tasks (entity extraction, memory update, response generation, etc.). The user sees one logical assistant; the multi-model orchestration is invisible. This project adopts the pattern through a first-class Operation Registry rather than an ad-hoc service/job convention.
- **Hypomnema** — personal Obsidian vault integration. In this project, Hypomnema is the first concrete context provider: it exposes vault notes as typed context entities with their relational structure (backlinks, tags, frontmatter) preserved. Runs as its own Compose service, reading from a shared volume populated by Obsidian Sync (or Obsidian Headless Sync in server environments). Mirrors Obsidian's open-meaning approach: it exposes vault primitives (Vault, Note, Tag) without imposing typed interpretations on content — frontmatter `type:`/`status:` are preserved as data, not read as authoritative subtypes or lifecycle. This is why a Note declares no `status` slot in the schema language (ADR-013).
- **Export-to-vault** — separate project that exports Claude.ai conversations into the Obsidian vault. Once running, exported conversations become referenceable context entities via Hypomnema, closing a useful loop.
- **Context provider** — the pluggable interface for adding new typed context entities to the system. Each provider knows how to search, render, serialize-for-prompt, and (optionally) suggest itself given conversation context.
- **Context entity** — a typed, identifiable thing that can be attached to a thread (Products, Parts, Documents, Persons, Conversations, ArchitectureDecisions, etc.). Each has a type, identity, renderable representation, and a serialization for the LLM.
- **Attachment / pinning** — explicit user action of associating a context entity with a thread. Pinned items are visible above the composer and serialized into prompts.
- **Context Set** — a durable, host-native, project-style collection of context references, owned by the `core` provider (type `core.context_set`). Distinct from the transient set of context attached to a single conversation: a conversation may *attach* a Context Set, and the host resolves its members into usable context. Members are ordered (v0) and are either **concrete entity references** or **query-shaped references** (a stored provider search resolving to zero-or-more entities). Named "Context Set" rather than "Project" (overloaded by Claude.ai/Linear) or "Workspace" (an account/team container).
- **Suggestion strip** — UI surface below the composer showing context items the system thinks might be relevant. Fed by lexical, embedding, graph, and (optionally) LLM signals.
- **ExternalStoreRuntime** — assistant-ui's runtime pattern where message state lives outside the React component tree, in a store fed by the server. The frontend shape that makes server-authoritative streaming work cleanly. It supplies the chat runtime/UI affordances, while the host-owned store still handles event-log reconciliation and recovery. See `design-notes/streaming-runtime-notes.md`.
- **Server-authoritative streaming** — the architecture where the server owns message state, generates responses to durable storage, and clients are subscribers that can reconnect and resume. The opposite — client-persisted message state — is what some chat tools do and what creates the disconnect-loses-the-response failure mode this project explicitly avoids.
- **Conversation-as-entity** — the (still open) idea that a completed conversation can itself become a referenceable context entity in future threads. Pin "the conversation where we worked out the streaming protocol" the way you'd pin a Product.
- **Host primitive** — a defined API the host exposes to extensions: entity declarations, suggestion proposals, pipeline lifecycle subscriptions, UI affordance requests, and tool exposures via MCP. Extensions interact with the host only through these — never through direct surface manipulation. See ADR-010.
- **Type envelope (schema language)** — the three-part declaration a provider emits per entity type: a JSON Schema (structure only), a sibling presentation-hints object (display semantics expressed via JSON Pointers), and a routing envelope (`type`, `envelope_version`/`type_version`, `provider`, `custom_renderer`). The host's generic renderer consumes it; instances on the wire are lean (`{ type, id, data }`), referencing the once-sent declaration by `type`. See ADR-013.
- **Operation Registry** — the host-owned registry of provider-declared model-adjacent operations (`core.chat.respond`, `core.memory.extract`, `hypomnema.context.retrieve`). Each operation declares schemas, model-profile needs, prompt strategy, scheduling/retry policy, side effects, and token accounting. Core registers built-ins; extensions can register operations only for granted hook points. See ADR-014.
- **Context Budget Planner** — the host-owned prompt admission layer. For each model request, it calculates the effective prompt budget, accounts for fixed prompt/tool/schema costs, admits history and context by classed policy, degrades oversized attached entities from full content to summary/reference/fetchable handles, and records omissions. It also triggers bounded compaction safety nets when async summarization falls behind. See ADR-016.
- **Universal entity reference** — the single cross-provider triple `{ provider, type, id, label? }` used wherever one entity points at another (backlinks, Context Set members, resolved body links). `id` is a globally-unique provider URI (e.g. a vault-scoped `hypomnema://` URI). One envelope for all providers, not per-kind. See ADR-013.
- **MCP** — Model Context Protocol. Adopted in this project for tool-shaped extension capabilities (tools, resources, prompts). A subset of the broader extension surface defined by ADR-010.
- **ACP** — Agent Client Protocol (Zed). Considered as a model and ultimately not adopted as a protocol — its primitives are editor-shaped (files, terminals, diffs). Its general design pattern (host-mediated control surfaces, capability negotiation, subprocess isolation) informed ADR-010.
- **Skills (skills.sh)** — Anthropic's packaging format for self-contained expertise (a `SKILL.md` plus supporting files). In this project, skills are content packages extensions can ship and the host can catalog/pin to a thread; they are not a first-class extension primitive. See ADR-015.
- **Coolify** — the deployment platform used for the personal hosted instance (Coolify Cloud → Hetzner). Manages the Docker Compose-based deployment. See ADR-011.

## Inspirations & where they apply

- **Iris** — closest reference for what a memory-shaped, multi-operation, server-authoritative AI chat product looks like in practice. Studied under the license's educational-use grant. Used as a convergence/divergence reference for design decisions: where Iris and these ADRs independently arrive at the same shape, the pattern gains validation; where they diverge, the divergence is intentional and informative. Iris is *not* a migration source — patterns can be learned from, but the codebase itself is not available for reuse.
- **Continue.dev** — context provider plugin interface. Closest reference for how typed context should plug in.
- **Cursor** — the `@`-mention picker pattern; the chip-based pinned-context UI above the composer.
- **Linear** — keyboard-first command palette feel; snappy state updates; how a polished productivity app should feel.
- **Claude.ai / ChatGPT** — resumable conversations, edit-and-regenerate, branching threads. Reference-grade chat UX.
- **assistant-ui** — the React component library to build on. Its runtime abstraction (especially `ExternalStoreRuntime`) is the right shape for this, with a host-owned external store adapting ADR-004 events into assistant-ui messages.
- **Glean** — commercial reference for "team AI workspace grounded in your data." Different target audience and openness but useful for understanding the category.
- **Obsidian's plugin model** — the "core stays simple; plugins register typed extensions" pattern is the model for how context providers should compose.
- **Event sourcing patterns (EventSauce et al.)** — the persistence model. The event log is the wire protocol; conversation state is a fold over events.
- **Granola** — passive context association (notes auto-link to meetings/attendees). Useful precedent for non-intrusive suggestion UX.
- **MCP, LSP, DAP, ACP** — the host-peer protocol family. Subprocess isolation, capability negotiation, host-mediated primitives, explicit versioning. The shape ADR-010 borrows for the extension surface.

## What it's not

- **Not a code editor.** Cursor and Continue do this well; this is for thought work, not coding work. ACP exists for the code-editor case and is explicitly not adopted here as a protocol.
- **Not a customer-facing product.** Internal/team tool. No marketing pages, no signups, no billing.
- **Not another generic ChatGPT clone.** Open WebUI exists; the differentiation is typed-entity grounding and extensibility-without-forking, not chat-in-general.
- **Not a replacement for Obsidian.** Obsidian remains the canonical home for notes. This is a layer that consumes Obsidian (and other sources) as context.
- **Not a frontend for Iris**, at least not as a goal in itself. Iris-as-backend isn't available (license-restricted, not my codebase) and coexistence (Iris as an upstream provider) was ruled out; this is a clean, independent rebuild.
- **Not an agent framework.** Agents can be built on top eventually; the core is grounded chat, not autonomous task execution.
