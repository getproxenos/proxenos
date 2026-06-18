import type { ThreadListItemResponse } from './transport'

/**
 * External thread-list adapter (streaming-runtime-notes §4, decision 9/10).
 *
 * The sidebar thread list is host state, not assistant-ui runtime state:
 * `GET /api/threads` returns the caller's ACTIVE threads ordered by
 * `updated_at` (D2), and these pure folds map that wire shape into the
 * `ThreadListItem` the sidebar renders. Selection stays route-driven
 * (D1 `ThreadRoute` / `selectThread`) — this module never owns "which
 * thread is current"; it only owns the list projection. Keeping it pure
 * (no fetch, no DOM) is what makes the mapping/reconcile logic unit-testable.
 */

export interface ThreadListItem {
  id: string
  /** Null until D4's auto-titler sets one (rendered via {@link threadDisplayTitle}). */
  title: string | null
  status: string
  /** ATOM-8601 string from the server; list ordering is server-authoritative. */
  updatedAt: string
}

/**
 * Map the `GET /api/threads` JSON array into list items. Title is
 * normalized so a missing, null, or blank server title collapses to
 * `null` (one "needs a display fallback" case, not three).
 */
export const mapThreadListResponse = (raw: ThreadListItemResponse[]): ThreadListItem[] =>
  raw.map((item) => ({
    id: item.id,
    title: typeof item.title === 'string' && item.title.trim() !== '' ? item.title : null,
    status: item.status,
    updatedAt: item.updated_at,
  }))

/** Display label for a thread whose title is still null (pre-auto-title). */
export const threadDisplayTitle = (item: ThreadListItem): string => item.title ?? 'Untitled thread'

/**
 * Optimistic archive (decision 10): drop the thread from the active list
 * immediately, before the server confirms. The next `mapThreadListResponse`
 * over a fresh `GET /api/threads` reconciles the authoritative truth.
 */
export const removeThread = (items: ThreadListItem[], id: string): ThreadListItem[] =>
  items.filter((item) => item.id !== id)

/**
 * Mint a client-side thread id for a new thread (decision 9). The thread
 * is opaque and ordered by `updatedAt`, so a v4 client id composes with the
 * server's v7 ids. No backend call happens until the first message lazily
 * creates the thread; it appears in the list after that turn re-fetches.
 */
export const newThreadId = (): string => crypto.randomUUID()
