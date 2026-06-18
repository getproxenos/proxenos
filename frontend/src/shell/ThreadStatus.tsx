import type { ThreadBanner } from '../store/threadView'

/**
 * Presentational status surfaces for the chat thread (D6). Kept out of
 * {@link ThreadSurface} — and free of the assistant-ui runtime — so each render
 * branch is render-testable in isolation (mirrors the MarkdownText/CopyButton
 * split). {@link ThreadSurface} owns the *decision* (via `deriveThreadView`);
 * these own the *markup*. Real-browser visual polish is flagged for Beau's
 * live check.
 */

/**
 * Pre-hydration "loading" and post-hydration "empty" placeholders, shown in
 * place of the message list when the thread has no messages to render.
 * `aria-busy` on loading is the honest "work in progress, not stalled" cue.
 */
export function ThreadPlaceholder({ status }: { status: 'loading' | 'empty' }) {
  if (status === 'loading') {
    return (
      <div className="thread-loading" role="status" aria-busy="true">
        <span className="thread-status-title">Loading conversation…</span>
      </div>
    )
  }
  return (
    <div className="thread-empty" role="status">
      <span className="thread-status-title">No messages yet</span>
      <span className="thread-status-detail">Send a message to start the conversation.</span>
    </div>
  )
}

/**
 * The terminal/transient banner rendered after the message list. A FAILED turn
 * is an honest error affordance (`role="alert"`) carrying the host's
 * `error_summary`; a CANCELLED turn is a neutral "you stopped this" notice
 * (`role="status"`) — deliberately NOT the failure styling, since a stop is not
 * an error (D8). `cancelling` is the transient hint while the terminal event is
 * in flight. `none` renders nothing.
 */
export function ThreadStatusBanner({ banner }: { banner: ThreadBanner }) {
  switch (banner.kind) {
    case 'none':
      return null
    case 'failed':
      return (
        <div className="thread-status thread-status-failed" role="alert">
          <span className="thread-status-title">The assistant hit an error and stopped.</span>
          {banner.errorSummary ? (
            <span className="thread-status-detail">{banner.errorSummary}</span>
          ) : null}
        </div>
      )
    case 'cancelled':
      return (
        <div className="thread-status thread-status-cancelled" role="status">
          <span className="thread-status-title">You stopped this response.</span>
        </div>
      )
    case 'cancelling':
      return (
        <div className="thread-status thread-status-cancelling" role="status" aria-busy="true">
          <span className="thread-status-title">Stopping…</span>
        </div>
      )
  }
}
