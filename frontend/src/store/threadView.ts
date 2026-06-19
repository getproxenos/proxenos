import type { ThreadState } from './types'

/**
 * Pure, side-effect-free derivation of *what the chat surface should render*
 * for the focused thread (D6 — honest status rendering). It collapses the
 * reducer's per-thread state (`runStatus`, `messages`, `errorSummary`,
 * `hydrated`) into a small discriminated union so the component is a dumb
 * switch and every render branch is unit-testable off real reducer output —
 * no DOM, no assistant-ui runtime, no browser.
 *
 * The honesty contract (handoff §4, D6/D7/D8):
 *  - never a silent stall: a thread that hasn't replayed yet says "loading",
 *    a thread that finished replay with nothing says "empty";
 *  - a FAILED turn keeps its partial assistant text AND shows a failure
 *    affordance carrying `error_summary` (reducer `runStatus: 'failed'`);
 *  - a CANCELLED turn keeps its partial text marked *stopped* — NOT routed
 *    through the failure banner, NO `errorSummary` (D8: cancel ≠ error, the
 *    reducer deliberately leaves `errorSummary` clear for cancels);
 *  - the `'cancelling'` transient (Stop pressed, terminal not yet folded)
 *    surfaces an optional "Stopping…" hint.
 */

/** The terminal/transient affordance shown alongside an existing message list. */
export type ThreadBanner =
  | { kind: 'none' }
  | { kind: 'failed'; errorSummary: string | null }
  | { kind: 'cancelled' }
  | { kind: 'cancelling' }

/**
 * What to render for the focused thread.
 *  - `loading`: selected but pre-hydration and nothing has streamed in yet.
 *  - `empty`:   hydrated (or live-confirmed) with no messages — a fresh thread.
 *  - `ready`:   render the message list plus `banner` (which may be `none`).
 */
export type ThreadView =
  | { status: 'loading' }
  | { status: 'empty' }
  | { status: 'ready'; banner: ThreadBanner }

/**
 * Derive the render view from reducer state. `null`/`undefined` (the focused
 * thread's reducer state not yet materialized) reads as `loading`.
 *
 * Ordering note: terminal FAILED/CANCELLED are checked before the
 * empty/loading fallback so they always surface — though in practice a
 * terminal turn always has at least the `user_message_submitted` message, so
 * the zero-message branch only ever fires for idle/streaming threads.
 */
export function deriveThreadView(thread: ThreadState | null | undefined): ThreadView {
  if (!thread) return { status: 'loading' }

  if (thread.runStatus === 'failed') {
    return { status: 'ready', banner: { kind: 'failed', errorSummary: thread.errorSummary } }
  }
  if (thread.runStatus === 'cancelled') {
    return { status: 'ready', banner: { kind: 'cancelled' } }
  }

  if (thread.messages.length === 0) {
    return thread.hydrated ? { status: 'empty' } : { status: 'loading' }
  }

  if (thread.runStatus === 'cancelling') {
    return { status: 'ready', banner: { kind: 'cancelling' } }
  }

  return { status: 'ready', banner: { kind: 'none' } }
}
