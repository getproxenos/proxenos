# Open Questions

The active edges. Things not yet decided, in roughly the order they feel pressing this week. This list is meant to be a working notebook — added to freely, deleted from when something gets resolved, reorganized when patterns emerge.

Not a roadmap. Not a backlog. A scratchpad for thinking.

> **Working note on how reference systems get used here.** When I study another product (a research paper, an open-source project, a friend's design notes — most often, Iris), the analytical move I try to make is: where does this reference and my design independently converge? (validation, more confidence in the direction) Where do we diverge? (intentional choices worth defending out loud) What does the reference have that my design hasn't addressed? (candidate concerns to consider) What does my design specify that this reference doesn't have? (territory I'm charting on my own, worth treating deliberately). That's a more disciplined consumption of reference material than "what should I adopt from this?" — and where reference material is licensed, it's the appropriate one. Annotations like *"one reference uses X"* below are doing this work.

---

## Currently chewing on

- **What is the schema language for entity types?** *Resolved — JSON Schema (structure) + a sibling presentation-hints object (display semantics, JSON Pointers) + a routing envelope. See ADR-013.*

- **Transclusion design.** When a referenced entity's content is pulled into another entity's rendered or serialized form (a Note body's `[[wiki-link]]`, a Context Set member, a citation expanded inline), how deep does it expand, and in what shape? Open: depth limits; expansion shape (pill / summary / full body); cycle detection; prompt-budget integration. Touches the wire protocol (ADR-004/010) and the response pipeline, and interacts with attached-entity serialization below. The largest deferred design piece — likely earns its own ADR. See `design-notes/transclusion-notes.md`.

- **How do conversations themselves become referenceable context entities?** Pinning "the conversation where we worked out the streaming protocol" the way I'd pin a Product is appealing. But: at what granularity (whole thread, individual turn, promoted-decision-only)? Who promotes (explicit user action, automatic detection, both)? How does this interact with privacy and permissions once multi-user lands?

- **Suggestion engine signal mix.** Lexical match is cheap. Embedding similarity is one library call. Graph walks (parent product → its parts) are domain-aware and the most legible to users. LLM-based "what's relevant?" is the most powerful and the most expensive. Which combinations, in what order, for what query rate? Probably start lexical + graph; add embedding when personal-vault size warrants it; defer LLM ranking until the simpler signals are saturated.

  *Worth noting that one mature reference takes a different position — embeddings + recency + frequency + type bonus, no lexical, no graph, no rerank. It works in practice for that system's scale. The divergence is real and worth defending explicitly: my pitch for lexical + graph rests on entity-graph-walkability that the reference doesn't have (it has no cross-entity relationships to walk). Without that structure, embeddings alone are a reasonable starting point; with it, graph signals should pay off.*

- **What is the search request/result shape?** Providers expose a `search` capability (ADR-003) but no request or result schema exists. Hypomnema's `hmn` already shows the shape of the problem: three modalities (filesystem / content / semantic) with different raw result shapes, all carrying `vault` + `vault_name` + path. Working position is a normalized host-facing envelope — a universal entity reference plus a modality-tagged `evidence` union. Overlaps query-shaped references (a vault-slice is a stored search) and the suggestion signal mix above. The Context Set walkthrough exercised this shape against a real vault-slice (a `query_request` resolving to a result list of `{ ref, evidence, meta }` rows). Remaining gap: cursor/offset **pagination** beyond `limit` + `truncated`. See `design-notes/search-shape-notes.md`.

- **Picker shape: hierarchical or flat?** Cursor-style `@product:` and then drill into parts is structured but adds a step. Flat-with-filters is faster for common cases but harder to scope. Probably: flat by default with type-aware autocomplete; hierarchical when an attached parent already constrains the picker scope. Worth prototyping both.

- **Attached entity serialization into the prompt.** Mostly resolved by ADR-016's budget modes: small explicit attachments can render `full`; large ones degrade to `summary`, `reference`, or `fetchable`. Remaining open piece is the ergonomics and schema for the fetch tool/operation. This still interacts with **transclusion design** above — the "how deep / what shape" of an expanded reference is the same question as the large-entity degradation policy.

- **Conversation promotion ritual.** What does the UX for "this conversation produced something worth keeping" look like? Inline button per message? End-of-thread prompt? Automatic detection ("this thread mentioned 'we decided' three times")? Different promotion targets (`ArchitectureDecision`, `OpenQuestion`, `Inspiration`, `Friction`) probably need different capture surfaces.

## Architecture-shaped

- **Iris: replace or coexist?** Migration is not available — Iris is a commercial codebase I don't own and the license prohibits both redistribution and competing-product creation. The real choice is between a clean Symfony rebuild informed by what I've learned from Iris (replace) and treating Iris as one upstream provider that the new system consumes alongside Hypomnema (coexist). Replace lets me design the schema language and extension surface from day one without backwards constraints; coexist lets me keep using Iris's memory/heartbeat work while building the workspace layer separately. Coexist seems more likely given Iris's strong shape-level convergence with my ADRs on most architectural questions — there's room for both systems to talk to each other without their abstractions clashing — but neither is decided.

- **Where does the "14 operations" pattern live?** *Resolved — a first-class Operation Registry. Operations are provider-owned declarations with input/output schemas, model-profile requirements, prompt/rendering strategy, context grants, scheduling, retry policy, side-effect declaration, and token accounting. Core registers built-ins; extensions can register operations only through granted ADR-010 hook points. See ADR-014 and `design-notes/operation-registry.md`.*

- **Streaming approach in the Symfony core.** Mercure is the obvious Symfony-native choice for SSE. FrankenPHP has native streaming and is gaining traction. A separate streaming service (Node/Go) in front of the Symfony backend is a heavier but more flexible option. The cost of getting this wrong is high — streaming UX is one of the explicit Iris pain points being addressed. Probably worth a small prototype on Mercure first.

  *Frontend runtime boundary resolved separately: assistant-ui's `ExternalStoreRuntime` is the UI/runtime adapter, and the host-owned store handles live events, cursor replay, normalization, idempotent folding, cancellation, branch state, and side-payload fetches. The remaining open question here is transport/deployment, not whether to build a custom React chat runtime. See `design-notes/streaming-runtime-notes.md`. One reference uses Reverb (Pusher-protocol WSS) in Laravel with the frontend on `laravel-echo`; the pattern (private channel per user, minimal-event broadcasts with separate fetch for tool details, polled HTTP fallback during reconnection) is portable across transports.*

- **Extension discovery mechanism.** ADR-010 commits to subprocess + JSON-RPC but not to *how* the host learns about which extensions are available. Options: a static manifest file listing extensions and their endpoints; Docker Compose labels the host scans on startup; a registry the host queries; a filesystem convention (drop a manifest in a known directory). Probably manifest-file + Compose-label hybrid, but worth thinking through. Skills are deliberately narrower than this: ADR-015 uses a host-local skill catalog fed by local roots and extension declarations, not a general extension-discovery mechanism.

- **Connector primitive wire details.** *Mostly resolved for v0.* Connectors are out-of-process transport adapters that submit normalized inbound messages and subscribe to host-owned stream events. Identity resolution, authorization, persistence, request construction, cancellation state, and recording stay in the host gateway; connectors own transport parsing/auth, delivery formatting, rate limits, and acknowledgements. Remaining implementation detail: exact JSON-RPC method names and error envelopes once the protocol schema is drafted. See `design-notes/connector-primitive-notes.md`.

- **Trust tier enforcement.** ADR-010 mentions trust tiers as a natural consequence of host-mediated primitives, but doesn't specify how they're enforced. Per-extension capability grants? Signed manifests? Nothing in v0 since it's all my code anyway? Probably nothing in v0 with the framework designed so it can be added later — but worth not painting myself into a corner.

- **Embedding pipeline location.** pgvector in the Symfony core handles simple cases. For hybrid search, rerankers, or anything ML-shaped, an extension in Python is the cleaner choice. The line between "core" and "extension" here is fuzzy — worth defining it before building the wrong thing.

  *One reference uses pgvector in the core for embedding generation + cosine search across multiple typed tables (memories, truths) and reports no friction. The "simple cases work fine in core" branch is validated; the Python-extension branch remains untested by available references.*

- **MCP server exposure scope and auth.** ADR-010 keeps the option open of exposing the system as an MCP server to other hosts (Claude Desktop, Cursor). When implemented, it raises questions about per-user OAuth scoping, what providers are exposed via MCP vs. internal-only, versioning of the exposed surface, and how team-deployment users get per-user MCP endpoints. Designed for, deferred in implementation.

- **Auth model for eventual multi-user.** OIDC/SSO from the start, or password-first with SSO later? Probably OIDC, given how often I end up integrating with corporate SSO. But Clerk/Auth0/Supabase Auth are all faster to bootstrap; the decision is partly about willingness to swap later. ADR-010's host-mediated model means an auth provider can plug in as an extension if the abstraction is right.

- **Cross-provider type overlay.** ADR-013 makes references universal (`{ provider, type, id }`), but the same underlying thing can surface through more than one provider: an exported Claude conversation is a Note via Hypomnema *and* could be a Conversation via a dedicated provider. Can two providers reference the same underlying thing, and if so, which type's schema/hints win when it's rendered or cited? This is the "conversation-as-entity" question (above) seen from the reference layer. See `design-notes/extension-augmentation-notes.md`.

- **Augmentation surface for Context Sets.** The Context Set primitive (ADR-013, `core.context_set`) deliberately keeps provider-specific actions, summaries, suggestions, and lifecycle hooks *out* of its schema — they stay extension-shaped. Open: how extensions register those augmentations against the primitive. Likely a pipeline-hook subcategory under ADR-010, using ADR-014's operation/hook split where model-adjacent work is involved. One of the augmentation kinds — an **artifact-target** default (a Context Set supplying the default create-target for conversation-written artifacts) — is now named by ADR-017, so the registration shape should accommodate it. See `design-notes/extension-augmentation-notes.md`.

- **Read/write artifacts.** *Resolved — ADR-017.* A conversation can create and modify shared context documents in the workspace, closing the Claude.ai read-only-project-files gap. Types declare a per-type/per-provider `capabilities` map (`create`/`update`/`delete`/`rename`) in the ADR-013 envelope; absence means read-only and the addition bumps `envelope_version` to `"1"` additively. The core ships a baseline host-storage-backed `core.document` so a zero-extension install can still save; extensions advertise their own types (e.g. write-enabled `hypomnema.note`) when configured. Capability surface is versioned/stable (ADR-013), execution is a synthesized `external_write` operation (ADR-014), runtime availability is negotiated per-deployment (ADR-010), and a write emits a citable entity into the thread (ADR-004). Remaining gaps: optimistic-concurrency depth, `external_write` confirmation/autonomy policy, bulk artifacts, the `core://` URI form. See ADR-017 and `design-notes/artifact-capabilities-walkthrough.md`.

- **Auto-promotion of long-term context.** Should there be a mechanism whereby frequently-accessed pieces of context (memory entries, attached references, anything else) get *automatically* promoted to a more durable, more universally-injected tier? One reference design has this — memories that get accessed often enough become "truths" that surface across all conversations, with LLM-driven crystallization and conflict detection — and I rely on it in daily use today. The current design has nothing equivalent and probably doesn't need it for v0, but the question is real: what's the v1 story for "stable facts about a user"? Session 05 confirmed the entity shapes fit ADR-013 (`Memory`, `Truth`, `TruthConflict`, `ConversationSummary`), but the promotion/crystallization/conflict behavior belongs as extension augmentation and prompt-serialization hooks, not core schema. See `design-notes/memory-primitives-walkthrough.md`.

- **Token usage accounting.** *Mostly resolved for v0.* Token usage is recorded per operation run, keyed by operation id rather than a closed enum, with provider, model, prompt/completion/cache-read/cache-write/thought tokens, tenant/user/thread/turn where present, latency, and estimated-vs-provider-reported status. Remaining gap: the reporting UI and rollup taxonomy. See ADR-014 and `design-notes/operation-registry.md`.

- **Prompt assembly / cache-breakpoint allocation.** *Resolved — ADR-018.* Prompt producers register a Prompt Declaration once (routing envelope + JSON Schema for the request data + assembly hints); per-request rendering is lean and host-planned. Ordering is a mergeable model of named slots + numeric weight + `before`/`after` anchors, with operator-policy guarding stable-prefix slots. Cache breakpoints are applied by a single host policy layer to the last non-empty message in eligible groups, capped by the provider's `max_breakpoints`. Context is host-granted as snapshots, universal references, or handles — declarations express need, the host decides the concrete subset. Remaining gaps: context-grant vocabulary, render-failure semantics, token-budget reporting back to ADR-016, provider-specific cache adapters, and the cross-primitive `capability_type` wrapper at the ADR-010 boundary. See ADR-018 and `design-notes/prompt-declaration-walkthrough.md`.

- **Context-window compaction strategy.** *Resolved at the architecture level — model requests go through a host-owned Context Budget Planner; recent turns, older turns, attached entities, query results, transclusions, and retrieved context are admitted by classed budget policy; compaction is async with a bounded sync safety net. See ADR-016 and `design-notes/context-window-management.md`. Remaining implementation details: default allocation ratios, provider tokenizer strategy, and `context.fetch` ergonomics.*

- **Long-running streaming tasks vs. queue workers.** If/when agents enter scope (delegated tasks that run for minutes with live tool-call broadcasting), the queue-worker model doesn't fit — tasks need a dedicated long-running process with inline retry/backoff, not a release-and-requeue model. One reference uses a separate `iris:agent`-style daemon for this. Worth flagging now even though it's deferred, because the deployment topology (ADR-011) needs room for it.

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
- Whether Skills (skills.sh) should be treated as a first-class extension category (alongside entity types and tools) or as content that extensions happen to ship. *Resolved — skills are content packages cataloged and pinned by the host, not a first-class extension primitive. See ADR-015 and `design-notes/skills-content-extensions.md`.*
