# Open Questions

The active edges. Things not yet decided, in roughly the order they feel pressing this week. This list is meant to be a working notebook — added to freely, deleted from when something gets resolved, reorganized when patterns emerge.

Not a roadmap. Not a backlog. A scratchpad for thinking.

---

## Currently chewing on

- **What is the schema language for entity types?** ADR-012 commits to schema-driven rendering with a presentation-agnostic schema; ADR-010 needs the schema to ride inside JSON-RPC messages between host and extension. JSON Schema is the obvious starting point but doesn't natively express display semantics (which field is the title, which is the summary, which is a status enum with these colors). Some hint layer is needed. Options: extend JSON Schema with `x-` keywords; layer a separate "presentation hints" object alongside the schema; invent a small DSL. Probably JSON Schema + hint object, but worth thinking through before committing.

- **How do conversations themselves become referenceable context entities?** Pinning "the conversation where we worked out the streaming protocol" the way I'd pin a Product is appealing. But: at what granularity (whole thread, individual turn, promoted-decision-only)? Who promotes (explicit user action, automatic detection, both)? How does this interact with privacy and permissions once multi-user lands?

- **Suggestion engine signal mix.** Lexical match is cheap. Embedding similarity is one library call. Graph walks (parent product → its parts) are domain-aware and the most legible to users. LLM-based "what's relevant?" is the most powerful and the most expensive. Which combinations, in what order, for what query rate? Probably start lexical + graph; add embedding when personal-vault size warrants it; defer LLM ranking until the simpler signals are saturated.

- **What is the search request/result shape?** Providers expose a `search` capability (ADR-003) but no request or result schema exists. Hypomnema's `hmn` already shows the shape of the problem: three modalities (filesystem / content / semantic) with different raw result shapes, all carrying `vault` + `vault_name` + path. Working position is a normalized host-facing envelope — a universal entity reference plus a modality-tagged `evidence` union. Overlaps query-shaped references (a vault-slice is a stored search) and the suggestion signal mix above. See `design-notes/search-shape-notes.md`.

- **Picker shape: hierarchical or flat?** Cursor-style `@product:` and then drill into parts is structured but adds a step. Flat-with-filters is faster for common cases but harder to scope. Probably: flat by default with type-aware autocomplete; hierarchical when an attached parent already constrains the picker scope. Worth prototyping both.

- **Attached entity serialization into the prompt.** Three options: (a) direct injection of all serialized entities into the system prompt, (b) tool-call-based retrieval where the model asks for context as needed, (c) hybrid where small entities inject directly and large ones are tool-call-fetched on demand. Probably (c), but the size threshold and the tool-call ergonomics need design.

- **Conversation promotion ritual.** What does the UX for "this conversation produced something worth keeping" look like? Inline button per message? End-of-thread prompt? Automatic detection ("this thread mentioned 'we decided' three times")? Different promotion targets (`ArchitectureDecision`, `OpenQuestion`, `Inspiration`, `Friction`) probably need different capture surfaces.

## Architecture-shaped

- **Iris: migrate, replace, or coexist?** The PHP/Symfony commitment in ADR-007 keeps the migration path viable — Iris's Laravel backend could plausibly be reshaped into the Symfony core rather than rebuilt from scratch. Pragmatic migration would expose Iris's existing backend as a context provider and let a new frontend gradually take over. A clean rebuild abandons Iris but loses any existing memory and data. Coexistence (use both for different things) is also possible. Still not decided; the decision is now less language-constrained than before.

- **Where does the "14 operations" pattern live?** A registry of named operations with assigned models, callable by the response pipeline? An agent graph? A small DSL? The simplest thing is a typed dictionary keyed by operation name with model + prompt-template values, callable from the response pipeline. Probably start there and let pain push toward something heavier. Under ADR-010, individual operations can also be exposed as pipeline-hook extension points so third-party extensions can inject custom steps.

- **Streaming approach in the Symfony core.** Mercure is the obvious Symfony-native choice for SSE. FrankenPHP has native streaming and is gaining traction. A separate streaming service (Node/Go) in front of the Symfony backend is a heavier but more flexible option. The cost of getting this wrong is high — streaming UX is one of the explicit Iris pain points being addressed. Probably worth a small prototype on Mercure first.

- **Extension discovery mechanism.** ADR-010 commits to subprocess + JSON-RPC but not to *how* the host learns about which extensions are available. Options: a static manifest file listing extensions and their endpoints; Docker Compose labels the host scans on startup; a registry the host queries; a filesystem convention (drop a manifest in a known directory). Probably manifest-file + Compose-label hybrid, but worth thinking through.

- **Trust tier enforcement.** ADR-010 mentions trust tiers as a natural consequence of host-mediated primitives, but doesn't specify how they're enforced. Per-extension capability grants? Signed manifests? Nothing in v0 since it's all my code anyway? Probably nothing in v0 with the framework designed so it can be added later — but worth not painting myself into a corner.

- **Embedding pipeline location.** pgvector in the Symfony core handles simple cases. For hybrid search, rerankers, or anything ML-shaped, an extension in Python is the cleaner choice. The line between "core" and "extension" here is fuzzy — worth defining it before building the wrong thing.

- **MCP server exposure scope and auth.** ADR-010 keeps the option open of exposing the system as an MCP server to other hosts (Claude Desktop, Cursor). When implemented, it raises questions about per-user OAuth scoping, what providers are exposed via MCP vs. internal-only, versioning of the exposed surface, and how team-deployment users get per-user MCP endpoints. Designed for, deferred in implementation.

- **Auth model for eventual multi-user.** OIDC/SSO from the start, or password-first with SSO later? Probably OIDC, given how often I end up integrating with corporate SSO. But Clerk/Auth0/Supabase Auth are all faster to bootstrap; the decision is partly about willingness to swap later. ADR-010's host-mediated model means an auth provider can plug in as an extension if the abstraction is right.

## UX edges

- **Branching: how does it interact with attached context?** assistant-ui supports message branching. When you branch from a turn, do the attached context entities carry over, reset, or become independently editable? Probably carry over but be editable in the new branch. Which branch becomes the canonical version for conversation promotion?

- **Surfacing the suggestion strip without it being noisy.** Always visible? Only when confidence is high? Collapsible? Probably collapsible with a small affordance, expanding on hover/focus. Confidence threshold needs to be tuned against real use.

- **How do citations render inline?** When the model cites a pinned `Part` by name, that should be a clickable affordance opening the Part's expanded view — not just a footnote-style number. Inline pills, probably. Where does the citation come from — the model's text output (parsed), a structured citation event, or both?

- **What happens to context as the conversation gets long?** Long threads will accumulate attached entities. Do they stay attached forever (and keep getting serialized into every prompt)? Do they auto-expire after N turns? Does the user explicitly unpin? Some combination — probably explicit pin/unpin with a "stale context" indicator after a turn count.

- **When does the schema-driven renderer feel insufficient?** ADR-012 commits to deferring the code escape hatch until a real case demands it. Worth keeping a list of cases where I find the generic renderer pinching, so the decision about *when* to add the escape hatch is informed by evidence rather than speculation.

## Deferred (worth tracking but not now)

- Mobile/native client. ADR-001/002/010/012 preserve the optionality; actually building one is deferred until the web client has been in real use.
- Voice input/output. Cool but distracting.
- Image generation. Out of scope for v0.
- Realtime collaboration on a thread (multiple users typing simultaneously). Defer past v1.
- Federation / cross-instance entity sharing. Way out.
- A plugin marketplace. Way out — but ADR-010 makes the eventual one tractable if the moment comes.
- All-in-one desktop install (single `docker compose up` from a fresh machine with no Coolify in the loop). Plausible but not a near-term priority.

## Things I keep flip-flopping on

- Whether to expose any OpenAI-compatible endpoint at all. ADR-008 says no, but compatibility with Msty/LibreChat-as-frontend for quick checks would be useful for development. Maybe a debug-only endpoint that doesn't ship to users?
- Whether the `Friction` type is real or just a synonym for `OpenQuestion` with a different mood.
- Whether attached context should be ordered (the user picks the order, which affects prompt construction) or unordered.
- Whether Skills (skills.sh) should be treated as a first-class extension category (alongside entity types and tools) or as content that extensions happen to ship.
