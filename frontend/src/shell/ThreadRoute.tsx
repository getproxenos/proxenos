import { useEffect } from 'react'
import type { ReactNode } from 'react'
import { useParams } from 'react-router-dom'

interface ThreadRouteProps {
  onSelect: (threadId: string) => void
  children: ReactNode
}

/**
 * Wires the `/app/threads/:id` route to the host store: whenever the `:id`
 * param changes, it selects that thread (`setCurrentThread` + subscribe +
 * hydrate, supplied by the shell via `onSelect`). Rendering is delegated to
 * `children` so this stays a thin, testable seam — the router → currentThread
 * wiring is verified in isolation without dragging in the assistant-ui runtime.
 */
export function ThreadRoute({ onSelect, children }: ThreadRouteProps) {
  const { id } = useParams()
  useEffect(() => {
    if (id) onSelect(id)
  }, [id, onSelect])
  return <>{children}</>
}
