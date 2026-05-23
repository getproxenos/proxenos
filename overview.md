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

1. **Iris is plateauing.** It's the closest existing thing to what I want, but client-authoritative state, weak streaming UX, and slow upstream development are increasingly painful. The fact that extending Iris requires editing the live application source — putting customizations directly in conflict with upstream updates — is the specific pain that motivates the extensibility commitment in this project.
2. **The open ecosystem is thin in exactly this corner.** Open WebUI and AnythingLLM cover generic chat well. LibreChat and LobeChat cover multi-provider chat. Glean covers enterprise-search-with-chat (commercial only). Nobody combines typed-entity grounding, collaborative use, persistent memory, an extensibility model that doesn't require forking, and a polished UI in something I can self-host.

## Who it's for

In priority order:

1. **Me, daily.** Personal use against my Obsidian vault and Claude history.
2. **My team at work.** A small group with shared domain entities (products, parts, customers, projects) and a need for grounded chat that respects those entities. Internal-only deployment, no public cloud.
3. **Wider, eventually.** If it survives (1) and (2), it could become something other people self-host.

Anything beyond (2) is aspirational and should not influence near-term decisions.

## Glossary

Named things that come up repeatedly. Define once; use consistently.

- **Iris** — an existing team AI chat platform with persistent and group-based memory, multiple internal model operations, currently in use. This project may evolve from Iris, replace it, or coexist alongside it; that's not yet decided.
- **The 14 operations** — Iris's pattern of orchestrating distinct model calls for distinct sub-tasks (entity extraction, memory update, response generation, etc.). The user sees one logical assistant; the multi-model orchestration is invisible. This project will likely adopt a similar pattern.
- **Hypomnema** — personal Obsidian vault integration. In this project, Hypomnema is the first concrete context provider: it exposes vault notes as typed context entities with their relational structure (backlinks, tags, frontmatter) preserved. Runs as its own Compose service, reading from a shared volume populated by Obsidian Sync (or Obsidian Headless Sync in server environments).
- **Export-to-vault** — separate project that exports Claude.ai conversations into the Obsidian vault. Once running, exported conversations become referenceable context entities via Hypomnema, closing a useful loop.
- **Context provider** — the pluggable interface for adding new typed context entities to the system. Each provider knows how to search, render, serialize-for-prompt, and (optionally) suggest itself given conversation context.
- **Context entity** — a typed, identifiable thing that can be attached to a thread (Products, Parts, Documents, Persons, Conversations, ArchitectureDecisions, etc.). Each has a type, identity, renderable representation, and a serialization for the LLM.
- **Attachment / pinning** — explicit user action of associating a context entity with a thread. Pinned items are visible above the composer and serialized into prompts.
- **Suggestion strip** — UI surface below the composer showing context items the system thinks might be relevant. Fed by lexical, embedding, graph, and (optionally) LLM signals.
- **ExternalStoreRuntime** — assistant-ui's runtime pattern where message state lives outside the React component tree, in a store fed by the server. The frontend shape that makes server-authoritative streaming work cleanly.
- **Server-authoritative streaming** — the architecture where the server owns message state, generates responses to durable storage, and clients are subscribers that can reconnect and resume. Opposite of Iris's current client-persisted model.
- **Conversation-as-entity** — the (still open) idea that a completed conversation can itself become a referenceable context entity in future threads. Pin "the conversation where we worked out the streaming protocol" the way you'd pin a Product.
- **Host primitive** — a defined API the host exposes to extensions: entity declarations, suggestion proposals, pipeline lifecycle subscriptions, UI affordance requests, and tool exposures via MCP. Extensions interact with the host only through these — never through direct surface manipulation. See ADR-010.
- **MCP** — Model Context Protocol. Adopted in this project for tool-shaped extension capabilities (tools, resources, prompts). A subset of the broader extension surface defined by ADR-010.
- **ACP** — Agent Client Protocol (Zed). Considered as a model and ultimately not adopted as a protocol — its primitives are editor-shaped (files, terminals, diffs). Its general design pattern (host-mediated control surfaces, capability negotiation, subprocess isolation) informed ADR-010.
- **Skills (skills.sh)** — Anthropic's packaging format for self-contained expertise (a SKILL.md plus supporting files). On the radar as a content format extensions can ship; lower architectural impact than MCP.
- **Coolify** — the deployment platform used for the personal hosted instance (Coolify Cloud → Hetzner). Manages the Docker Compose-based deployment. See ADR-011.

## Inspirations & where they apply

- **Continue.dev** — context provider plugin interface. Closest reference for how typed context should plug in.
- **Cursor** — the `@`-mention picker pattern; the chip-based pinned-context UI above the composer.
- **Linear** — keyboard-first command palette feel; snappy state updates; how a polished productivity app should feel.
- **Claude.ai / ChatGPT** — resumable conversations, edit-and-regenerate, branching threads. Reference-grade chat UX.
- **assistant-ui** — the React component library to build on. Its runtime abstraction (especially `ExternalStoreRuntime`) is the right shape for this.
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
- **Not a frontend for Iris**, at least not as a goal in itself. Iris-as-backend is one possible path; full rebuild is another. The shape of the new frontend is the same either way.
- **Not an agent framework.** Agents can be built on top eventually; the core is grounded chat, not autonomous task execution.
