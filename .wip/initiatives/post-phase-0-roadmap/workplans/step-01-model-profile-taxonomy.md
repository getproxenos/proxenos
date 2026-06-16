# Workplan — step-01 · F1 model-profile / task-intent taxonomy

Name the model-selection layer that the spine, the operation registry, and
every memory/truth feature will consume — operator-level only (ADR-008). The
deliverable is mostly an ADR plus a config refactor; the resolver and its
caller defaults move with the rename. Full handoff:
[handoff-model-profile-taxonomy.md](../../../current-handoffs/handoff-model-profile-taxonomy.md).

Started: 2026-06-16.

## Decisions (made here, feed later steps)

- **Canonical intent list: `proxenos.task.{chat, reason, extract, summarize,
  embed.text}`.** `code` is *reserved-in-ADR-only* for v0 — declared but not
  yet bound to a profile. The ADR holds the full list; config defines only
  what is consumed today.
- **Bare intent = the default/balanced quality variant.** Composed names
  appear only when a profile actually splits (`proxenos.task.reason.deep`
  only when `task.reason` does). No `(intent × quality)` resolver machinery.
- **Bespoke profiles are first-class peers.** Any feature may bind to any
  profile name; the `proxenos.task.*` prefix is a readability convention,
  not a type. The OpenRouter bridge example reorganises as
  `proxenos.task.chat.generic` — a bespoke peer that *satisfies* `task.chat`
  while documenting the bridge swap pattern from ADR-023.
- **Per-profile schema fields:** `platform`, `model`, `options`, plus a
  reserved (not-yet-emitted) `kind: completion|embedding` marker. Reserved
  values are documented in the ADR; the resolver defaults to `completion`
  and v0 ships only completion profiles. When `embed.text` lands, `kind:
  embedding` and a `dimension: int` arrive together.
- **Feature → profile bindings live in config + env now**
  (`PROXENOS_{FEATURE}_MODEL_PROFILE`), and graduate into the ADR-014
  operation declaration later. Sites with a single binding (the chat loop)
  keep a hard-coded default; sites that want overridability ship the env
  pattern in their constructor.
- **Quality variants stay naming-convention-only for v0.** No composed-axis
  resolver. ADR records the deferred option with the trigger that would flip
  it (multiple `task.*` features each needing latency vs. cost tradeoffs).
- **Keep `platform:`** (symfony/ai's term), not `provider:`. No `xes.model.*`
  indirection layer.

## Chunks

Each chunk is a single focused commit.

1. **Rename profiles in `config/packages/proxenos.yaml`** —
   `chat.frontier` → `proxenos.task.chat`,
   `chat.frontier_alt` → `proxenos.task.chat.generic`. Update the swap-by-config
   comment to reference the new names. Same diff on `config/services_test.yaml`
   so the test rebinding still resolves.
2. **Update the one caller default + stale comments.**
   `ChatRespondRequest::$modelProfile` defaults to `'proxenos.task.chat'`. Sweep
   `chat.frontier` mentions in code comments (`ChatRespondLoop`,
   `ModelProfileResolver`, `ResolvedModel`, `RecordingInMemoryPlatform`,
   `config/services.yaml`, `config/packages/ai.yaml`) to the new name.
3. **Author ADR-027: model-profile / task-intent taxonomy.** Two layers
   (profile→`{platform, model, options}`; feature→profile via config + env),
   two dimensions (intent + quality variant by naming convention), flat-map
   convention, bespoke-peer rule, `platform` term, `kind` marker for
   embeddings, env pattern with worked example. Cross-references and small
   amendments to ADR-008 / ADR-014 / ADR-023 (one-line "see ADR-027" notes
   on each).
4. **Document the env pattern with a worked example** in the
   `config/packages/proxenos.yaml` header comment
   (`PROXENOS_{FEATURE}_MODEL_PROFILE`) — the first downstream feature has a
   copy-paste pattern.

## Test strategy

- `make test` — full suite must stay green. The rename is the only
  behaviour-affecting change; no fixtures or assertions hard-code
  `chat.frontier` beyond comments/docs (`grep`'d already).
- `make lint` — Psalm + Rector + PHPStan + PHP-CS-Fixer all clean.
- No new tests are warranted at this layer: the resolver contract is
  unchanged, and the existing `ChatRespondLoop` tests already exercise the
  default-profile path via the in-memory platform rebinding.

## Definition of done

- `proxenos.model_profiles` carries the intent-taxonomy names; the two
  existing profiles are renamed and `services_test.yaml` matches.
- `ChatRespondRequest::$modelProfile` defaults to `proxenos.task.chat`; no
  call sites pass `modelProfile:` explicitly today, so the default carries
  the entire migration.
- ADR-027 is recorded with: two layers, two dimensions, flat-map convention,
  bespoke-peer rule, `platform` term, embeddings `kind`, feature→profile env
  pattern, and the canonical intent list (with `code` reserved). ADR-008 /
  ADR-014 / ADR-023 each carry a one-line cross-reference back.
- `make test` and `make lint` green.
- The env pattern (`PROXENOS_{FEATURE}_MODEL_PROFILE`) appears with a worked
  example so the first F2/F3/F4 feature has something to copy.

## Open questions to resolve during execution

- **Where exactly the ADR lives.** `engineering/decisions/` exists but is
  empty (`.gitkeep` only) — all current ADRs live in
  `architecture-decisions.md` at repo root. Lean: append ADR-027 to the
  same file to stay with its siblings; the engineering/decisions split is
  its own initiative.
- **How aggressively to amend ADR-014.** The handoff says "lightly amend".
  Lean: add a one-line "feature→profile bindings: see ADR-027 for the v0
  env pattern; the registry absorbs them in F2" under ADR-014's open
  questions section.
- **`kind` marker shipping shape.** Reserved-but-not-emitted vs.
  reserved-and-emitted-as-default. Lean: documented as the v0 default
  (`completion`), but not stamped into the existing two profiles — adding
  a key everywhere now is churn for zero behaviour change. The ADR is
  explicit that omission means `completion`.
