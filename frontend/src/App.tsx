import { useCallback, useEffect, useRef, useState } from 'react'
import {
  AssistantRuntimeProvider,
  ComposerPrimitive,
  MessagePrimitive,
  ThreadPrimitive,
  useExternalStoreRuntime,
} from '@assistant-ui/react'
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
import './index.css'

/**
 * Real `ExternalStoreRuntime` adapter (handoff §4, ADR-026).
 *
 * Replaces the Phase 0.0 echo scaffold from this same file. The store
 * is host-owned (`design-notes/streaming-runtime-notes.md` §4):
 * messages are projected from the event log via the reducer in
 * `./store/reducer.ts`, so live (Mercure) + replay (cursor endpoint)
 * fold through one union. assistant-ui only renders — it never owns
 * thread or branch state.
 *
 * Live verification (NOT covered by Vitest — see PR test plan):
 *  - EventSource handshake against the real Mercure hub;
 *  - End-to-end streaming from Anthropic through the loop into the UI;
 *  - mercureAuthorization cookie attachment in a real browser.
 *
 * Reducer reconciliation (Vitest in `reducer.test.ts`) covers cases
 * 1–5 from `streaming-runtime-notes.md` §6.
 */
export function App() {
  const [storeState, setStoreState] = useState(initialStoreState)
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
   * Bootstrap + initial replay. Order matters:
   *  1. /api/me/bootstrap → identity, CSRF, Mercure descriptor.
   *  2. For each subscribed topic, subscribe to Mercure FIRST.
   *  3. THEN call /events?after=0 to backfill.
   *
   * Subscribing before replaying is what makes case-1 (reconnect race)
   * work: any live event that lands during replay also arrives via
   * the cursor page, and the reducer dedupes on event id so both
   * arrivals fold once (handoff §4).
   */
  useEffect(() => {
    let cancelled = false
    void (async () => {
      try {
        const boot = await fetchBootstrap()
        if (cancelled) return
        csrfTokenRef.current = boot.csrf_token
        hubUrlRef.current = boot.mercure.hub_url

        const threadIds = boot.mercure.subscribed_topics
          .map(extractThreadIdFromTopic)
          .filter((x): x is string => x !== null)

        for (const threadId of threadIds) subscribeThread(threadId)

        const firstThreadId = threadIds[0]
        if (firstThreadId) {
          setStoreState((prev) => setCurrentThread(prev, firstThreadId))
          await hydrateThread(firstThreadId)
        }
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
  }, [hydrateThread, subscribeThread])

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
      <main className="app">
        <header className="app-header">
          <h1>Proxenos</h1>
          <p>
            assistant-ui SPA —{' '}
            {storeState.currentThreadId
              ? `thread ${storeState.currentThreadId.slice(0, 8)}…`
              : 'no thread selected'}
          </p>
          {bootstrapError && (
            <p role="alert" className="app-error">
              Bootstrap failed: {bootstrapError}
            </p>
          )}
        </header>

        <ThreadPrimitive.Root className="thread">
          <ThreadPrimitive.Viewport>
            <ThreadPrimitive.Messages
              components={{
                UserMessage: () => (
                  <div className="message message-user">
                    <span className="message-role">user</span>
                    <MessagePrimitive.Parts />
                  </div>
                ),
                AssistantMessage: () => (
                  <div className="message message-assistant">
                    <span className="message-role">assistant</span>
                    <MessagePrimitive.Parts />
                  </div>
                ),
              }}
            />
          </ThreadPrimitive.Viewport>

          <ComposerPrimitive.Root className="composer">
            <ComposerPrimitive.Input className="composer-input" placeholder="Say something…" />
            <ComposerPrimitive.Send className="composer-send">Send</ComposerPrimitive.Send>
          </ComposerPrimitive.Root>
        </ThreadPrimitive.Root>
      </main>
    </AssistantRuntimeProvider>
  )
}

const extractThreadIdFromTopic = (topic: string): string | null => {
  const match = /^\/threads\/([0-9a-f-]{36})\/events$/.exec(topic)
  return match ? match[1]! : null
}
