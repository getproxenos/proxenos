import type { ConversationEventEnvelope } from './types'

/**
 * Transport-layer helpers — kept in their own module so the reducer
 * (`reducer.ts`) stays pure and unit-testable without DOM globals
 * (fetch / EventSource). The adapter (`adapter.ts`) composes these
 * with the reducer.
 *
 * Hard-to-test live behaviors (EventSource handshake, real Mercure
 * connectivity, browser caching) are flagged in the PR test plan as
 * "needs Beau's live browser/stack check" — there is no credential-free
 * way to exercise them in CI.
 */

export interface BootstrapDescriptor {
  user: { id: string; email: string }
  tenant: { id: string; slug: string; name: string }
  csrf_token: string
  mercure: {
    hub_url: string
    topic_template: string
    subscribed_topics: string[]
  }
}

export interface ReplayPage {
  events: ConversationEventEnvelope[]
  next_after: number | null
  has_more: boolean
}

/** GET /api/me/bootstrap — identity, CSRF, Mercure descriptor. */
export const fetchBootstrap = async (): Promise<BootstrapDescriptor> => {
  const res = await fetch('/api/me/bootstrap', { credentials: 'same-origin' })
  if (!res.ok) throw new Error(`bootstrap failed: ${res.status}`)
  return (await res.json()) as BootstrapDescriptor
}

/** GET /api/threads/{id}/events?after=&limit= — cursor replay. */
export const fetchEventsAfter = async (
  threadId: string,
  afterSequence: number,
  limit = 200,
): Promise<ReplayPage> => {
  const url = `/api/threads/${threadId}/events?after=${afterSequence}&limit=${limit}`
  const res = await fetch(url, { credentials: 'same-origin' })
  if (!res.ok) throw new Error(`replay fetch failed: ${res.status}`)
  return (await res.json()) as ReplayPage
}

/** Drain every page after `afterSequence` for full hydration. */
export const fetchAllEventsAfter = async (
  threadId: string,
  afterSequence: number,
): Promise<ConversationEventEnvelope[]> => {
  const collected: ConversationEventEnvelope[] = []
  let cursor = afterSequence
  while (true) {
    const page = await fetchEventsAfter(threadId, cursor)
    collected.push(...page.events)
    if (!page.has_more || page.next_after === null) break
    cursor = page.next_after
  }
  return collected
}

/** POST /api/threads/{id}/messages — `onNew` from the SPA composer. */
export const submitMessage = async (
  threadId: string,
  text: string,
  csrfToken: string,
): Promise<void> => {
  const res = await fetch(`/api/threads/${threadId}/messages`, {
    method: 'POST',
    credentials: 'same-origin',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': csrfToken,
    },
    body: JSON.stringify({ text }),
  })
  if (!res.ok) throw new Error(`submit failed: ${res.status}`)
}

/** POST /api/threads/{id}/runs/{turnId}/cancel — best-effort cancel. */
export const requestCancel = async (
  threadId: string,
  turnId: string,
  csrfToken: string,
): Promise<void> => {
  const res = await fetch(`/api/threads/${threadId}/runs/${turnId}/cancel`, {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'X-CSRF-Token': csrfToken },
  })
  if (!res.ok) throw new Error(`cancel failed: ${res.status}`)
}

/**
 * Subscribe to a Mercure topic via EventSource. Returns an unsubscribe
 * fn. The mercureAuthorization cookie attaches automatically; no
 * Authorization header is sent (handoff §3).
 *
 * Reconnection: EventSource handles low-level reconnection, but the
 * adapter is responsible for replaying any events missed during the
 * disconnect (`fetchAllEventsAfter`). The Mercure server's
 * `Last-Event-ID` mechanism is NOT relied on for replay; we use the
 * cursor endpoint instead because it's the same source of truth as
 * projection rebuild (handoff §1).
 */
export const subscribeToTopic = (
  hubUrl: string,
  topic: string,
  onMessage: (event: ConversationEventEnvelope) => void,
  onReconnect: () => void,
): (() => void) => {
  const url = new URL(hubUrl)
  url.searchParams.append('topic', topic)
  const source = new EventSource(url.toString(), { withCredentials: true })

  source.addEventListener('message', (evt) => {
    try {
      const parsed = JSON.parse(evt.data) as ConversationEventEnvelope
      onMessage(parsed)
    } catch {
      // Drop malformed frames silently — replay endpoint is canonical.
    }
  })
  source.addEventListener('open', () => {
    onReconnect()
  })

  return () => source.close()
}
