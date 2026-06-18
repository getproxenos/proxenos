import { useState } from 'react'
import { useNavigate } from 'react-router-dom'

import { newThreadId, threadDisplayTitle } from '../store/threadList'
import type { ThreadListItem } from '../store/threadList'
import { threadRoutePath } from './routes'

interface ThreadSidebarProps {
  threads: ThreadListItem[]
  currentThreadId: string | null
  /** Rename via `POST …/rename`; rejects (400 on blank/over-long) surface inline. */
  onRename: (id: string, title: string) => Promise<void>
  /** Archive via `POST …/archive` (optimistic remove → reconcile). */
  onArchive: (id: string) => void
}

/** The projection column is varchar(200); reject over-long titles client-side
 *  too so the user gets an immediate, friendly message instead of a 400. */
const MAX_TITLE_LENGTH = 200

/**
 * Left navigation rail (D3): the external thread-list adapter rendered as a
 * switch/new/rename/archive surface over `GET /api/threads`. Selection is
 * route-driven (D1) — switching and "New" navigate to `/app/threads/:id` and
 * let `ThreadRoute` → `selectThread` do `setCurrentThread + subscribe +
 * hydrate`; this component never selects a thread directly. "New" mints a
 * client-side UUID (decision 9) and routes immediately; the thread appears in
 * the list after its first message folds and the shell re-fetches.
 */
export function ThreadSidebar({
  threads,
  currentThreadId,
  onRename,
  onArchive,
}: ThreadSidebarProps) {
  const navigate = useNavigate()
  const [editingId, setEditingId] = useState<string | null>(null)
  const [draftTitle, setDraftTitle] = useState('')
  const [renameError, setRenameError] = useState<string | null>(null)

  const startNewThread = (): void => {
    navigate(threadRoutePath(newThreadId()))
  }

  const beginRename = (item: ThreadListItem): void => {
    setRenameError(null)
    setEditingId(item.id)
    setDraftTitle(item.title ?? '')
  }

  const cancelRename = (): void => {
    setEditingId(null)
    setRenameError(null)
  }

  const commitRename = async (id: string): Promise<void> => {
    const title = draftTitle.trim()
    if (title === '') {
      // Empty rename is a no-op cancel, not an error.
      cancelRename()
      return
    }
    if (title.length > MAX_TITLE_LENGTH) {
      setRenameError(`Title must be ${MAX_TITLE_LENGTH} characters or fewer.`)
      return
    }
    setEditingId(null)
    try {
      await onRename(id, title)
      setRenameError(null)
    } catch {
      setRenameError('Could not rename the thread. Please try again.')
    }
  }

  return (
    <nav className="app-sidebar" aria-label="Threads">
      <div className="app-sidebar-header">
        <p className="app-sidebar-title">Threads</p>
        <button type="button" className="app-newthread" onClick={startNewThread}>
          New
        </button>
      </div>

      {renameError && (
        <p role="alert" className="app-error">
          {renameError}
        </p>
      )}

      {threads.length === 0 ? (
        <p className="app-sidebar-placeholder">No threads yet</p>
      ) : (
        <ul className="app-threadlist">
          {threads.map((item) => (
            <li
              key={item.id}
              className={
                item.id === currentThreadId ? 'app-thread-item is-active' : 'app-thread-item'
              }
            >
              {editingId === item.id ? (
                <input
                  className="app-thread-rename"
                  autoFocus
                  aria-label="Rename thread"
                  value={draftTitle}
                  onChange={(event) => setDraftTitle(event.target.value)}
                  onBlur={() => void commitRename(item.id)}
                  onKeyDown={(event) => {
                    if (event.key === 'Enter') void commitRename(item.id)
                    if (event.key === 'Escape') cancelRename()
                  }}
                />
              ) : (
                <button
                  type="button"
                  className="app-thread-switch"
                  onClick={() => navigate(threadRoutePath(item.id))}
                >
                  {threadDisplayTitle(item)}
                </button>
              )}
              <span className="app-thread-actions">
                <button type="button" aria-label="Rename thread" onClick={() => beginRename(item)}>
                  Rename
                </button>
                <button
                  type="button"
                  aria-label="Archive thread"
                  onClick={() => onArchive(item.id)}
                >
                  Archive
                </button>
              </span>
            </li>
          ))}
        </ul>
      )}
    </nav>
  )
}
