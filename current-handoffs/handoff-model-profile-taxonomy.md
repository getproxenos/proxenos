# Handoff — Model-Profile / Task-Intent Taxonomy (F1)

Name the model-selection layer that the spine, the operation registry, and every
memory/truth feature will consume — **operator-level only** (ADR-008: no user-facing
picker). This is a small, low-risk, load-bearing foundation. Land it before the spine so
everything downstream is named correctly from the start (renaming later is cheap but
touches more sites the longer you wait).

This is mostly a **design + minimal config refactor** session: produce the ADR + the
naming spec, and seed `proxenos.model_profiles` with the intent taxonomy by restructuring
the two profiles that already exist.

## Inputs to load

- `config/packages/proxenos.yaml` — the existing `parameters: proxenos.model_profiles`
  map (`chat.frontier`, `chat.frontier_alt`).
- `src/Ai/ModelProfile/` — `ModelProfileResolver`, `ConfigModelProfileResolver`,
  `ResolvedModel`. The resolver is the host-owned seam (ADR-023).
- `src/Ai/Chat/ChatRespondRequest.php` — the one current caller; `modelProfile` defaults
  to `'chat.frontier'`.
- `config/services.yaml` + `config/services_test.yaml` — the platform locator
  (`anthropic`, `generic.default`) and the test rebinding of `chat.frontier`.
- ADR-008 (provider/model is operator-level, no user picker), ADR-014 (operations
  declare a model-profile requirement), ADR-023 (the resolver seam), ADR-007 (extensions
  language-agnostic — irrelevant here but explains the symfony/ai `platform` term).

## Decisions to land (resolved in the post-Phase-0 roadmap discussion — encode, don't relitigate)

1. **Two layers, both already present in shape.**
   - *profile → `{platform, model, options}`* = `proxenos.model_profiles` (built).
   - *feature → profile* = the ADR-014 operation's model-profile requirement; expressed
     for v0 as lightweight env-overridable config, e.g.
     `PROXENOS_{FEATURE}_MODEL_PROFILE` with a sensible task-intent default.
2. **Two naming dimensions, composed by convention.**
   - *intent* — what the call is for: `proxenos.task.{chat, reason, extract, summarize,
     embed.text}` (`code` reserved — see open question).
   - *quality/latency variant* — the editorial tradeoff: `.fast` / `.deep` / `.frontier`.
     Today's `chat.frontier` is `task.chat` + `frontier`-quality.
3. **v0 = naming convention over ONE flat map; the resolver stays a dumb
   `name → profile` lookup.** Names encode both dims (`proxenos.task.reason`, and
   `proxenos.task.reason.deep` *only when actually split*). Bare intent = the
   default/balanced variant. No `(intent × quality)` 2D resolution machinery.
4. **Bespoke profiles are first-class peers.** A feature may bind to any profile name
   (`custom-consolidator`) — no special handling; that is already how `name → resolver`
   works. The `proxenos.task.*` prefix is a readability convention, not a type.
5. **Drop the `xes.model.*` branch.** "A concrete model used in one place" is just a
   bespoke profile. A model-alias / indirection layer is the deferred 2D machinery.
6. **Keep `platform:`** (symfony/ai's term), not `provider:`.
7. **`task.embed.text` is a different capability kind** — resolves to an embeddings
   client (no streaming, carries an embedding dimension). The profile schema likely needs
   a `kind` marker so the resolver routes completions vs embeddings correctly.

## Open sub-decisions to settle this session

- **Canonical intent list.** Is `code` in scope for Proxenos v0, or reserved-in-ADR-only?
  (Lean: reserve `code` and `reason`'s relationship to coding; declare only what's used.)
- **Declare-all vs declare-as-needed in config.** The resolver rejects a profile with no
  model, so every *config* entry needs a real model. Lean: the **ADR** holds the full
  canonical intent list; **config** defines only profiles actually consumed now (`chat`,
  plus `embed.text` if/when embeddings land), and documents the pattern for adding more.
- **Per-profile schema.** Pin the fields: `platform`, `model`, `options` (`max_tokens`,
  `temperature`, `stream_options`, …), `kind` (completion | embedding), and how the
  embedding dimension is expressed.
- **Where feature→profile bindings live.** Plain config + env now (this session) vs the
  ADR-014 operation declaration later. Lean: config now, graduate into the registry (F2).
- **Quality-variant evolution.** Confirm naming-convention-only for v0; record the
  composed-axis option as explicitly deferred, with what would trigger it.

## Scope — what to build/change

- **An ADR** ("model-profile / task-intent taxonomy") recording the two layers, the two
  dimensions, the flat-map convention, the bespoke-peer rule, the `platform` term, the
  embeddings `kind`, and the feature→profile binding pattern. Cross-reference and lightly
  amend ADR-008 / ADR-014 / ADR-023.
- **Seed the taxonomy in `proxenos.model_profiles`:** restructure the two existing
  profiles into the intent naming (e.g. `chat.frontier` → `proxenos.task.chat`; keep the
  generic/OpenRouter profile as the bridge example). Update the one caller default
  (`ChatRespondRequest::modelProfile`) and the test rebinding in
  `config/services_test.yaml` accordingly.
- **Document the feature→profile env pattern** (`PROXENOS_{FEATURE}_MODEL_PROFILE`) with a
  worked example, so the first real feature (a spine operation, a memory task) has a
  pattern to copy.

## Hard exclusions

- No user-facing model picker (ADR-008).
- No composed `(intent × quality)` resolver — naming convention only.
- No `xes.model.*` / model-alias indirection layer.
- No Operation Registry build (ADR-014) — only the *binding shape* (the feature→profile
  config pattern). The registry is F2.
- No new providers/bridges beyond the two already wired (`anthropic`, `generic.default`).

## Definition of done

- [ ] ADR recorded: two layers, two dimensions, flat-map convention, bespoke-peer rule,
      `platform` term, embeddings `kind`, feature→profile pattern; ADR-008/014/023
      cross-referenced/amended.
- [ ] `proxenos.model_profiles` restructured into the intent taxonomy; the existing two
      profiles renamed; default caller + `services_test.yaml` rebinding updated.
- [ ] `make test` + `make lint` green (the rename must not break the chat loop or its
      tests).
- [ ] The feature→profile env convention documented with a worked example.
- [ ] The canonical intent list + the open sub-decisions above resolved in the ADR.

## Downstream

- **F2 — Operation Registry** graduates the feature→profile binding from config into the
  operation declaration.
- **Track A — the spine** uses a `task.chat` profile for `response.generate`.
- **Track B — memory/truth features** map their many judgment tasks onto `task.reason`,
  recall/extraction onto `task.extract`, briefs/summaries onto `task.summarize`, and
  embeddings onto `task.embed.text` — each env-overridable to a bespoke profile when one
  feature genuinely needs its own model.
