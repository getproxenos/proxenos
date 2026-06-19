import { useCallback, useEffect, useRef, useState } from 'react'
import { Route, Routes, useNavigate } from 'react-router-dom'
import { AssistantRuntimeProvider, useExternalStoreRuntime } from '@assistant-ui/react'
import type { AppendMessage, ThreadMessageLike } from '@assistant-ui/react'

import {
  foldEvent,
  foldEvents,
  initialStoreState,
  markCancelRequested,
  markHydrated,
  setCurrentThread,
} from './store/reducer'
import { deriveThreadView } from './store/threadView'
import {
  archiveThread,
  createThread,
  fetchAllEventsAfter,
  fetchBootstrap,
  fetchMeSettings,
  fetchThreadList,
  renameThread,
  requestCancel,
  saveMeSettings,
  saveThreadSystemPrompt,
  submitMessage,
  subscribeToTopic,
} from './store/transport'
import type { BootstrapDescriptor } from './store/transport'
import { mapThreadListResponse, removeThread } from './store/threadList'
import type { ThreadListItem } from './store/threadList'
import { EmptyState } from './shell/EmptyState'
import { SystemPromptDialog } from './shell/SystemPromptDialog'
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
  const [threads, setThreads] = useState<ThreadListItem[]>([])
  // Which system-prompt editor is open (D10): the global default (off the
  // TopBar user menu) or the focused thread's override (off the active thread).
  const [promptDialog, setPromptDialog] = useState<'global' | 'thread' | null>(null)
  const csrfTokenRef = useRef<string | null>(null)
  const hubUrlRef = useRef<string | null>(null)
  const subscriptionsRef = useRef(new Map<string, () => void>())
  // Mirror of `threads` for stable callbacks (onNew reads it without
  // re-creating itself — and thus the runtime — on every list change).
  const threadsRef = useRef<ThreadListItem[]>([])
  const navigate = useNavigate()

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
      if (events.length > 0) setStoreState((prev) => foldEvents(prev, events))
    } catch {
      // Replay failure leaves the SPA reading whatever live events
      // arrive; the next reconnect/open triggers another attempt.
    } finally {
      // Mark hydrated once the replay attempt settles (success OR failure) so a
      // genuinely empty thread stops showing the loading placeholder instead of
      // stalling silently (D6). On failure this surfaces the empty state rather
      // than an honest "failed to load" — see D6 soft flag; live events still
      // fold in if/when they arrive.
      setStoreState((prev) => markHydrated(prev, threadId))
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
   * Re-fetch the active thread list and reconcile (decision 10). The list
   * is server-authoritative — ordering by `updatedAt`, archived rows hidden —
   * so reconciling is a straight replace. Called on initial bootstrap and
   * after every rename / archive / new-thread first turn. Subscriptions are
   * NOT touched here (bootstrap / reBootstrap / selectThread own those).
   */
  const refreshThreadList = useCallback(async (): Promise<void> => {
    try {
      const items = mapThreadListResponse(await fetchThreadList())
      threadsRef.current = items
      setThreads(items)
    } catch {
      // Leave the prior list in place; the next refresh retries.
    }
  }, [])

  /**
   * Re-fetch `/api/me/bootstrap` to mint a fresh Mercure subscriber JWT and
   * re-subscribe. A thread created mid-session (decision 9) is NOT in the
   * original token's subscribe scope — `SpaBootstrapController` enumerates one
   * topic per existing thread, no wildcard (OQ5) — so its live EventSource is
   * rejected until we re-bootstrap. We force-resubscribe the focused thread
   * (its stale, rejected EventSource is torn down and reopened under the new
   * cookie) and replay to backfill anything the live stream missed.
   */
  const reBootstrap = useCallback(
    async (focusThreadId: string | null): Promise<void> => {
      try {
        const descriptor = await fetchBootstrap()
        csrfTokenRef.current = descriptor.csrf_token
        hubUrlRef.current = descriptor.mercure.hub_url
        setBoot(descriptor)

        if (focusThreadId) {
          const stale = subscriptionsRef.current.get(focusThreadId)
          if (stale) {
            stale()
            subscriptionsRef.current.delete(focusThreadId)
          }
        }

        const threadIds = descriptor.mercure.subscribed_topics
          .map(extractThreadIdFromTopic)
          .filter((x): x is string => x !== null)
        for (const threadId of threadIds) subscribeThread(threadId)

        if (focusThreadId) void hydrateThread(focusThreadId)
      } catch {
        // Keep the prior session; the next refresh/new-thread retries.
      }
    },
    [subscribeThread, hydrateThread],
  )

  const onRenameThread = useCallback(
    async (threadId: string, title: string): Promise<void> => {
      const csrf = csrfTokenRef.current
      if (!csrf) throw new Error('bootstrap incomplete: no CSRF token')
      // Optimistic title; reconcile (or revert) from the next list fetch.
      setThreads((prev) => prev.map((t) => (t.id === threadId ? { ...t, title } : t)))
      try {
        await renameThread(threadId, title, csrf)
      } catch (err) {
        await refreshThreadList()
        throw err
      }
      await refreshThreadList()
    },
    [refreshThreadList],
  )

  const onArchiveThread = useCallback(
    (threadId: string): void => {
      const csrf = csrfTokenRef.current
      if (!csrf) return
      // Optimistic remove (decision 10); reconcile from the next list fetch.
      setThreads((prev) => removeThread(prev, threadId))
      if (storeState.currentThreadId === threadId) navigate('/')
      void (async () => {
        try {
          await archiveThread(threadId, csrf)
        } finally {
          await refreshThreadList()
        }
      })()
    },
    [navigate, refreshThreadList, storeState.currentThreadId],
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

        void refreshThreadList()
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
  }, [subscribeThread, refreshThreadList])

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
      // First-message gating: a brand-new client-minted thread (decision 9)
      // is not in the original bootstrap JWT's per-thread subscribe scope
      // (OQ5 — no wildcard), so any Mercure event published while we POST
      // the submit would be rejected at the hub. We pre-create the empty
      // thread row, re-bootstrap to mint a JWT covering it and subscribe,
      // and only THEN start the streaming submit — the user_message_submitted
      // event and the assistant deltas now land on a live subscription
      // instead of being held until the stream completes and arriving
      // all-at-once via cursor replay. ~100-200ms of extra latency before
      // the first delta, vs. the entire stream duration of silence before.
      // Existing threads skip the create + bootstrap and submit directly.
      const isNewThread = !threadsRef.current.some((t) => t.id === threadId)
      if (isNewThread) {
        await createThread(threadId, csrf)
        await reBootstrap(threadId)
      }
      await submitMessage(threadId, part.text, csrf)
      // Live deltas + user_message_submitted fold through Mercure.
      // No optimistic insert; event log is the source of truth.
      await refreshThreadList()
    },
    [storeState.currentThreadId, reBootstrap, refreshThreadList],
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

  // System-prompt editors (D10). Callbacks are memoized so the dialog's
  // load-on-open effect fires once — an unrelated store update (e.g. a live
  // Mercure delta re-rendering the shell) must not re-load and clobber an
  // in-progress edit. The global default loads from `GET /api/me/settings`;
  // the per-thread override has no GET endpoint, so it loads the folded value
  // off the focused thread (`ThreadState.systemPrompt`).
  const loadGlobalPrompt = useCallback(
    () => fetchMeSettings().then((s) => s.system_prompt_default),
    [],
  )
  const saveGlobalPrompt = useCallback((value: string | null): Promise<void> => {
    const csrf = csrfTokenRef.current
    if (!csrf) throw new Error('bootstrap incomplete: no CSRF token')
    return saveMeSettings(value, csrf)
  }, [])

  const currentSystemPrompt = currentThread?.systemPrompt ?? null
  const loadThreadPrompt = useCallback(
    () => Promise.resolve(currentSystemPrompt),
    [currentSystemPrompt],
  )
  const saveThreadPrompt = useCallback(
    (value: string | null): Promise<void> => {
      const csrf = csrfTokenRef.current
      const threadId = storeState.currentThreadId
      if (!csrf) throw new Error('bootstrap incomplete: no CSRF token')
      if (!threadId) throw new Error('no current thread')
      return saveThreadSystemPrompt(threadId, value, csrf)
    },
    [storeState.currentThreadId],
  )

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
          onOpenSettings={() => setPromptDialog('global')}
        />
        <div className="app-body">
          <ThreadSidebar
            threads={threads}
            currentThreadId={storeState.currentThreadId}
            onRename={onRenameThread}
            onArchive={onArchiveThread}
          />
          <main className="app-main">
            <Routes>
              <Route index element={<EmptyState />} />
              <Route
                path="threads/:id"
                element={
                  <ThreadRoute onSelect={selectThread}>
                    <ThreadSurface
                      view={deriveThreadView(currentThread)}
                      onEditSystemPrompt={() => setPromptDialog('thread')}
                    />
                  </ThreadRoute>
                }
              />
            </Routes>
          </main>
        </div>
      </div>

      {promptDialog === 'global' && (
        <SystemPromptDialog
          title="Default system prompt"
          description="Applied to every thread unless that thread sets its own override. Leave blank to clear."
          load={loadGlobalPrompt}
          save={saveGlobalPrompt}
          onClose={() => setPromptDialog(null)}
        />
      )}
      {promptDialog === 'thread' && storeState.currentThreadId && (
        <SystemPromptDialog
          title="Thread system prompt"
          description="Overrides your default for this thread only. Leave blank to clear and fall back to the default."
          load={loadThreadPrompt}
          save={saveThreadPrompt}
          onClose={() => setPromptDialog(null)}
        />
      )}
    </AssistantRuntimeProvider>
  )
}

const extractThreadIdFromTopic = (topic: string): string | null => {
  const match = /^\/threads\/([0-9a-f-]{36})\/events$/.exec(topic)
  return match ? match[1]! : null
}
