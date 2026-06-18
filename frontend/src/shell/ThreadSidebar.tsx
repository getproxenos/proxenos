interface ThreadSidebarProps {
  currentThreadId: string | null
}

/**
 * Left navigation rail. For D1 this is a placeholder — the real external
 * thread-list adapter over `GET /api/threads` and the assistant-ui ThreadList
 * land in D3. It only reflects which thread the route has selected so the
 * shell layout (sidebar / active thread / top bar) is in place and persistent.
 */
export function ThreadSidebar({ currentThreadId }: ThreadSidebarProps) {
  return (
    <nav className="app-sidebar" aria-label="Threads">
      <p className="app-sidebar-title">Threads</p>
      <p className="app-sidebar-placeholder">
        {currentThreadId ? `Active: ${currentThreadId.slice(0, 8)}…` : 'No thread selected'}
      </p>
    </nav>
  )
}
