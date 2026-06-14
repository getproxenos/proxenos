# Handoff — Gating Decisions (step 4)

Commit the two decisions that gate the build, so the vertical slice (step 5) stands on
fixed ground. They're independent of the remaining design work and of each other, but both
gate ADR-011's deployment topology, so resolve them in one session.

## Decision 1 — Iris: replace (decided)

**Decided — replace.** This project is a clean Symfony rebuild that replaces day-to-day
reliance on Iris; Iris remains a study reference only (the license permits educational
study, not redistribution or competing-product creation, and migration was never available).

**Ruled out — coexist** (consuming a running Iris instance as an upstream provider alongside
Hypomnema). The two systems run independently and never integrate — there is no Iris
provider and no data/operation flow between them. The license question this would have
raised (whether consuming a deployed instance as a provider is permitted) is therefore moot.

**ADR-011 topology consequence:** the Compose stack reserves no slot for an Iris provider
process, and no Iris adapter / reference-envelope conformance work is needed.

### Inputs
- ADR-006 (personal-first), ADR-011 (deployment). `reference/iris/iris-deep-dive.md`
  remains a study reference.

---

## Decision 2 — Streaming transport

The frontend runtime boundary is already settled (assistant-ui `ExternalStoreRuntime`, host-owned
store — see `design-notes/streaming-runtime-notes.md`). **This decision is transport /
deployment only.** It's the one your notes call high-cost-to-get-wrong, because streaming
UX is an explicit Iris pain point being addressed.

### Inputs
- ADR-007 (PHP/Symfony core), ADR-011 (deployment).
- `design-notes/event-sourced-conversations.md` — the Redis replay-buffer + WSS pattern
  Iris validates; conversation events are canonical regardless of transport.
- `design-notes/streaming-runtime-notes.md` — confirms this is transport, not runtime.

### Options and tradeoffs
| Option | For | Against |
|---|---|---|
| **Mercure** | Symfony-native SSE, simplest, smallest new surface | one more service in the topology; SSE-only |
| **FrankenPHP native streaming** | fewest moving parts, app server *is* the streamer | newer, less battle-tested in your stack |
| **Dedicated Node/Go service** | most flexible; matches Iris's Reverb+Redis shape | heaviest; a second language/runtime to operate |

### To record
Your note already says "prototype Mercure first." The decision to commit is the **v0
choice plus the fallback trigger** — i.e. what observed failure would make you switch
(e.g. "if replay/resume under reconnect can't be made clean on Mercure, move to a dedicated
service"). Record the v0 pick, the trigger, and the ADR-011 topology slot.

---

## Definition of done

- [x] Iris license question (Decision 1) — moot: replace means no running Iris instance is consumed.
- [x] Replace-or-coexist decided (replace), with ruled-out option and rationale.
- [ ] Streaming transport v0 choice + explicit fallback trigger recorded.
- [ ] ADR-011 amended with both topology consequences.
- [ ] `open-questions.md` updated: both "Architecture-shaped" items marked resolved.
