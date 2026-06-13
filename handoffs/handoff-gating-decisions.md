# Handoff — Gating Decisions (step 4)

Commit the two decisions that gate the build, so the vertical slice (step 5) stands on
fixed ground. They're independent of the remaining design work and of each other, but both
gate ADR-011's deployment topology, so resolve them in one session.

## Decision 1 — Iris: replace or coexist?

Gates ADR-011 (deployment topology). Migration is off the table — the license permits
educational study but prohibits redistribution and competing-product creation. The real
choice is **replace** (clean Symfony rebuild, Iris as reference only) vs. **coexist**
(Iris as one upstream provider consumed alongside Hypomnema).

### Inputs
- `reference/iris/iris-deep-dive.md` and the convergence/divergence document.
- ADR-006 (personal-first), ADR-011 (deployment), the Iris license terms.

### What to pressure-test
The notes lean **coexist** given how strongly Iris's shape converges with your ADRs. Before
committing, answer:

1. **The load-bearing license question.** Coexist means consuming a *running* Iris instance
   as a provider over its existing surface. Does the license permit consuming a deployed
   instance as a data/operation provider, as distinct from studying its code? This is the
   gate — flag it explicitly and resolve it before the architecture question.
2. **Does Iris expose a consumable surface,** or would coexist require wrapping it (and does
   wrapping cross the "competing product" line)?
3. **What does coexist cost at the wire boundary** — an Iris adapter conforming to the
   step-1 reference envelope and the ADR-010 provider contract? Is that adapter clean, or
   does Iris's internal model leak?

### To record
The decision, the ruled-out option, and the ADR-011 topology consequence (room for an Iris
provider process — or not). If the license question (1) resolves against consuming a
running instance, **replace** wins by default and the rest is moot.

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

- [ ] Iris license question (Decision 1, point 1) answered in writing.
- [ ] Replace-or-coexist decided, with ruled-out option and rationale.
- [ ] Streaming transport v0 choice + explicit fallback trigger recorded.
- [ ] ADR-011 amended with both topology consequences.
- [ ] `open-questions.md` updated: both "Architecture-shaped" items marked resolved.
