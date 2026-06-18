import { useCallback, useEffect, useRef, useState } from 'react'
import { Route, Routes } from 'react-router-dom'
import { AssistantRuntimeProvider, useExternalStoreRuntime } from '@assistant-ui/react'
import type { AppendMessage, ThreadMessageLike } from '@assistant-ui/react'

import {
  foldEvent,
  foldEvents,
  initialStoreState,
  markCancelRequested,
  setCurrentThread,
} from './store/reducer'
import {
  fetchAllEventsAfter,
  fetchBootstrap,
  requestCancel,
  submitMessage,
  subscribeToTopic,
} from './store/transport'
import type { BootstrapDescriptor } from './store/transport'
import { EmptyState } from './shell/EmptyState'
import { ThreadRoute } from './shell/ThreadRoute'
import { ThreadSidebar } from './shell/ThreadSidebar'
import { ThreadSurface } from './shell/ThreadSurface'
import { TopBar } from './shell/TopBar'
import './index.css'

/**
 * Persistent SPA shell (decision 8, ADR-026, handoff §4).
 *
 * The bootstrap / subscribe / hydrate logic is lifted UP into this shell so
 * Mercure subscriptions survive client-side route changes: the shell mounts
 * once under `BrowserRouter`, and switching threads only changes the route
 * (and `currentThreadId`) — it never tears down the store or its
 * subscriptions (the store is per-thread; handoff §4 case 3).
 *
 * Layout: a top bar (email + tenant + Sign out), a left thread sidebar
 * (placeholder until D3's ThreadList), and a center routed area —
 * `/app/threads/:id` selects + renders that thread, `/app` shows an
 * empty-state landing.
 *
 * Live verification (NOT covered by Vitest — see PR test plan):
 *  - EventSource handshake against the real Mercure hub;
 *  - End-to-end streaming from Anthropic through the loop into the UI;
 *  - mercureAuthorization cookie attachment in a real browser.
 *
 * Reducer reconciliation (Vitest in `store/reducer.test.ts`) covers cases
 * 1–5 from `streaming-runtime-notes.md` §6; the router→currentThread wiring
 * and the sign-out target are covered in `shell/*.test.tsx`.
 */
export function App() {
  const [storeState, setStoreState] = useState(initialStoreState)
  const [boot, setBoot] = useState<BootstrapDescriptor | null>(null)
  const [bootstrapError, setBootstrapError] = useState<string | null>(null)
  const csrfTokenRef = useRef<string | null>(null)
  const hubUrlRef = useRef<string | null>(null)
  const subscriptionsRef = useRef(new Map<string, () => void>())

  const hydrateThread = useCallback(async (threadId: string): Promise<void> => {
    // Read the current lastSeenSequence via the setState updater so
    // we never see a stale cursor under React 19's transition queue.
    const afterSequence = await new Promise<number>((resolve) => {
      setStoreState((prev) => {
        resolve(prev.threadsById[threadId]?.lastSeenSequence ?? 0)
        return prev
      })
    })
    try {
      const events = await fetchAllEventsAfter(threadId, afterSequence)
      if (events.length === 0) return
      setStoreState((prev) => foldEvents(prev, events))
    } catch {
      // Replay failure leaves the SPA reading whatever live events
      // arrive; the next reconnect/open triggers another attempt.
    }
  }, [])

  const subscribeThread = useCallback(
    (threadId: string): void => {
      const hubUrl = hubUrlRef.current
      if (!hubUrl) return
      if (subscriptionsRef.current.has(threadId)) return

      const topic = `/threads/${threadId}/events`
      const unsubscribe = subscribeToTopic(
        hubUrl,
        topic,
        (event) => {
          setStoreState((prev) => foldEvent(prev, event))
        },
        () => {
          // Reconnect: replay anything missed since the last seen seq.
          void hydrateThread(threadId)
        },
      )
      subscriptionsRef.current.set(threadId, unsubscribe)
    },
    [hydrateThread],
  )

  /**
   * Select the thread named by the `/app/threads/:id` route: focus it,
   * ensure a subscription (a no-op when bootstrap already covered it), and
   * replay anything missed. Stable so `ThreadRoute`'s effect only re-fires on
   * an actual id change, not on every shell render.
   */
  const selectThread = useCallback(
    (threadId: string): void => {
      setStoreState((prev) => setCurrentThread(prev, threadId))
      subscribeThread(threadId)
      void hydrateThread(threadId)
    },
    [subscribeThread, hydrateThread],
  )

  /**
   * Bootstrap + initial subscription. Order matters:
   *  1. /api/me/bootstrap → identity, CSRF, Mercure descriptor.
   *  2. For each subscribed topic, subscribe to Mercure.
   *
   * Subscribing before any replay is what makes case-1 (reconnect race)
   * work: a live event landing during replay also arrives via the cursor
   * page, and the reducer dedupes on event id so both fold once (handoff §4).
   * Thread *selection* is now driven by the route (`ThreadRoute`), not by
   * picking the first bootstrap topic.
   */
  useEffect(() => {
    let cancelled = false
    void (async () => {
      try {
        const descriptor = await fetchBootstrap()
        if (cancelled) return
        csrfTokenRef.current = descriptor.csrf_token
        hubUrlRef.current = descriptor.mercure.hub_url
        setBoot(descriptor)

        const threadIds = descriptor.mercure.subscribed_topics
          .map(extractThreadIdFromTopic)
          .filter((x): x is string => x !== null)

        for (const threadId of threadIds) subscribeThread(threadId)
      } catch (err) {
        if (!cancelled) {
          setBootstrapError(err instanceof Error ? err.message : 'bootstrap failed')
        }
      }
    })()
    const subscriptions = subscriptionsRef.current
    return () => {
      cancelled = true
      for (const unsubscribe of subscriptions.values()) unsubscribe()
      subscriptions.clear()
    }
  }, [subscribeThread])

  const currentThread = storeState.currentThreadId
    ? storeState.threadsById[storeState.currentThreadId]
    : null
  const messages = currentThread?.messages ?? []
  const runStatus = currentThread?.runStatus ?? 'idle'

  const convertMessage = useCallback(
    (message: (typeof messages)[number]): ThreadMessageLike => ({
      id: message.id,
      role: message.role,
      content: [{ type: 'text', text: message.text }],
    }),
    [],
  )

  const onNew = useCallback(
    async (appended: AppendMessage): Promise<void> => {
      const csrf = csrfTokenRef.current
      const threadId = storeState.currentThreadId
      if (!csrf) throw new Error('bootstrap incomplete: no CSRF token')
      if (!threadId) throw new Error('no current thread to submit into')
      const part = appended.content[0]
      if (!part || part.type !== 'text') {
        throw new Error('only text composer parts supported in v0')
      }
      await submitMessage(threadId, part.text, csrf)
      // Live deltas + user_message_submitted fold through Mercure.
      // No optimistic insert; event log is the source of truth.
    },
    [storeState.currentThreadId],
  )

  const onCancel = useCallback(async (): Promise<void> => {
    const csrf = csrfTokenRef.current
    const threadId = storeState.currentThreadId
    const turnId = currentThread?.activeTurnId
    if (!csrf || !threadId || !turnId) return
    setStoreState((prev) => markCancelRequested(prev, threadId))
    try {
      await requestCancel(threadId, turnId, csrf)
    } catch {
      // Cancel is best-effort; SPA holds 'cancelling' until a
      // terminal event resolves it (handoff §4 case 5).
    }
  }, [currentThread?.activeTurnId, storeState.currentThreadId])

  const runtime = useExternalStoreRuntime({
    messages,
    isRunning: runStatus === 'streaming' || runStatus === 'cancelling',
    convertMessage,
    onNew,
    onCancel,
  })

  return (
    <AssistantRuntimeProvider runtime={runtime}>
      <div className="app-shell">
        <TopBar
          email={boot?.user.email ?? null}
          tenantName={boot?.tenant.name ?? null}
          bootstrapError={bootstrapError}
        />
        <div className="app-body">
          <ThreadSidebar currentThreadId={storeState.currentThreadId} />
          <main className="app-main">
            <Routes>
              <Route index element={<EmptyState />} />
              <Route
                path="threads/:id"
                element={
                  <ThreadRoute onSelect={selectThread}>
                    <ThreadSurface />
                  </ThreadRoute>
                }
              />
            </Routes>
          </main>
        </div>
      </div>
    </AssistantRuntimeProvider>
  )
}

const extractThreadIdFromTopic = (topic: string): string | null => {
  const match = /^\/threads\/([0-9a-f-]{36})\/events$/.exec(topic)
  return match ? match[1]! : null
}
