import type {
  ConversationEventEnvelope,
  ConversationStoreState,
  HostMessage,
  ThreadState,
} from './types'

/**
 * Pure folds for the conversation store. The adapter wires these
 * against live (Mercure) + replay (cursor endpoint) deliveries; the
 * reducer enforces every reconciliation invariant from
 * `design-notes/streaming-runtime-notes.md` §6 (handoff §4):
 *
 *  1. Reconnect after hidden tab: caller fetches `events?after=
 *     thread.lastSeenSequence` and folds the result; duplicate
 *     deliveries dedupe via `seenEventIds`.
 *  2. Live + replay race: same envelope arriving via both transports
 *     folds once because `seenEventIds` is checked before any
 *     mutation.
 *  3. Thread switch during stream: per-thread state lives at
 *     `threadsById[threadId]`; switching changes
 *     `currentThreadId` without touching prior-thread reducer state.
 *  4. Duplicate cumulative-replace deltas (ADR-024): delta payload
 *     is "the cumulative text so far"; the fold REPLACES the part's
 *     text rather than appending. Replaying the same delta twice
 *     converges to the same projection.
 *  5. Cancel before terminal event: `markCancelRequested` flips
 *     runStatus to 'cancelling'; the next terminal event settles
 *     it.
 *  6. Side-payload fetch failure: not exercised in v0; the cache
 *     slot is reserved in the store shape so it doesn't have to be
 *     retro-fitted (handoff "Hard exclusions").
 *
 * Idempotency knob: the reducer dedupes on EVENT ID first, then
 * SEQUENCE — server stamps both monotonically and either is sufficient
 * to identify a fold-once event. ID is checked first so a malformed
 * sequence in a replay row can't mask a duplicate ID.
 */

export const initialThreadState = (threadId: string): ThreadState => ({
  threadId,
  lastSeenSequence: 0,
  seenEventIds: new Set<string>(),
  messages: [],
  runStatus: 'idle',
  activeTurnId: null,
  errorSummary: null,
})

export const initialStoreState = (): ConversationStoreState => ({
  currentThreadId: null,
  threadsById: {},
})

const ensureThread = (state: ConversationStoreState, threadId: string): ConversationStoreState => {
  if (state.threadsById[threadId]) return state
  return {
    ...state,
    threadsById: {
      ...state.threadsById,
      [threadId]: initialThreadState(threadId),
    },
  }
}

/** Fold a single event into the store, idempotent on (id, sequence). */
export const foldEvent = (
  state: ConversationStoreState,
  event: ConversationEventEnvelope,
): ConversationStoreState => {
  const withThread = ensureThread(state, event.thread_id)
  const thread = withThread.threadsById[event.thread_id]!

  // Idempotency check — runs before ANY mutation so live+replay races
  // (case 2) and replay-after-reconnect (case 1) fold once.
  if (thread.seenEventIds.has(event.id)) {
    return withThread
  }
  if (event.sequence <= thread.lastSeenSequence && thread.lastSeenSequence > 0) {
    // Out-of-band lower-sequence event AFTER we've already seen a
    // higher one. The event log is monotonic per-thread so this is
    // a duplicate from a stale source; treat it the same as
    // `seenEventIds` hit.
    return withThread
  }

  const nextThread = foldIntoThread(thread, event)
  return {
    ...withThread,
    threadsById: {
      ...withThread.threadsById,
      [event.thread_id]: nextThread,
    },
  }
}

/** Fold a batch (e.g. a replay page) preserving sequence order. */
export const foldEvents = (
  state: ConversationStoreState,
  events: ConversationEventEnvelope[],
): ConversationStoreState => {
  // Caller may not have pre-sorted (live+replay merge); sort by
  // sequence ASC so cumulative-delta convergence holds for any
  // delivery order (case 4 + out-of-order delta).
  const sorted = [...events].sort((a, b) => a.sequence - b.sequence)
  return sorted.reduce(foldEvent, state)
}

const foldIntoThread = (thread: ThreadState, event: ConversationEventEnvelope): ThreadState => {
  const seenEventIds = new Set(thread.seenEventIds).add(event.id)
  const lastSeenSequence = Math.max(thread.lastSeenSequence, event.sequence)

  switch (event.type) {
    case 'user_message_submitted':
      return applyUserMessageSubmitted(thread, event, seenEventIds, lastSeenSequence)
    case 'assistant_turn_created':
      return {
        ...thread,
        seenEventIds,
        lastSeenSequence,
        runStatus: thread.runStatus === 'cancelling' ? 'cancelling' : 'streaming',
        activeTurnId: event.turn_id,
      }
    case 'assistant_content_delta':
      return applyAssistantContentDelta(thread, event, seenEventIds, lastSeenSequence)
    case 'assistant_turn_completed':
      return applyAssistantTurnCompleted(thread, event, seenEventIds, lastSeenSequence)
    case 'assistant_turn_failed':
      return applyAssistantTurnFailed(thread, event, seenEventIds, lastSeenSequence)
    case 'assistant_turn_cancelled':
      return applyAssistantTurnCancelled(thread, event, seenEventIds, lastSeenSequence)
  }
}

const applyUserMessageSubmitted = (
  thread: ThreadState,
  event: ConversationEventEnvelope,
  seenEventIds: Set<string>,
  lastSeenSequence: number,
): ThreadState => {
  const messageId = String(event.payload.message_id ?? '')
  const text = String(event.payload.text ?? '')
  const message: HostMessage = {
    id: messageId,
    role: 'user',
    text,
    status: 'complete',
    turnId: null,
    position: thread.messages.length,
  }
  return {
    ...thread,
    seenEventIds,
    lastSeenSequence,
    messages: [...thread.messages, message],
  }
}

const applyAssistantContentDelta = (
  thread: ThreadState,
  event: ConversationEventEnvelope,
  seenEventIds: Set<string>,
  lastSeenSequence: number,
): ThreadState => {
  const messageId = String(event.payload.message_id ?? '')
  const text = String(event.payload.text ?? '')

  const existing = thread.messages.find((m) => m.id === messageId)
  if (existing) {
    // ADR-024 cumulative-replace: replace, never append. Folding the
    // same delta twice converges (case 4).
    const messages = thread.messages.map((m) =>
      m.id === messageId ? { ...m, text, status: 'streaming' as const } : m,
    )
    return { ...thread, seenEventIds, lastSeenSequence, messages }
  }

  const message: HostMessage = {
    id: messageId,
    role: 'assistant',
    text,
    status: 'streaming',
    turnId: event.turn_id,
    position: thread.messages.length,
  }
  return {
    ...thread,
    seenEventIds,
    lastSeenSequence,
    messages: [...thread.messages, message],
    runStatus: thread.runStatus === 'cancelling' ? 'cancelling' : 'streaming',
    activeTurnId: event.turn_id ?? thread.activeTurnId,
  }
}

const applyAssistantTurnCompleted = (
  thread: ThreadState,
  event: ConversationEventEnvelope,
  seenEventIds: Set<string>,
  lastSeenSequence: number,
): ThreadState => {
  const messageId = String(event.payload.message_id ?? '')
  const messages = thread.messages.map((m) =>
    m.id === messageId ? { ...m, status: 'complete' as const } : m,
  )
  return {
    ...thread,
    seenEventIds,
    lastSeenSequence,
    messages,
    runStatus: 'completed',
    activeTurnId: null,
  }
}

const applyAssistantTurnFailed = (
  thread: ThreadState,
  event: ConversationEventEnvelope,
  seenEventIds: Set<string>,
  lastSeenSequence: number,
): ThreadState => {
  const rawMessageId = event.payload.message_id
  const messageId = typeof rawMessageId === 'string' ? rawMessageId : null
  const messages =
    messageId === null
      ? thread.messages
      : thread.messages.map((m) => (m.id === messageId ? { ...m, status: 'failed' as const } : m))
  return {
    ...thread,
    seenEventIds,
    lastSeenSequence,
    messages,
    runStatus: 'failed',
    activeTurnId: null,
    errorSummary:
      typeof event.payload.error_summary === 'string' ? event.payload.error_summary : null,
  }
}

/**
 * Cooperative-cancel terminal (D7 backend `assistant_turn_cancelled`,
 * handoff §4 case 5). Mirrors {@link applyAssistantTurnFailed}: settle
 * `runStatus`, clear `activeTurnId`, and mark the partial assistant message
 * — but `'cancelled'` rather than `'failed'`, since a stopped turn is not an
 * error. `errorSummary` is intentionally left untouched (a cancel carries no
 * error; D7 emits `error_summary: ''`). Idempotent: a duplicate/late
 * envelope is dropped by `foldEvent`'s `seenEventIds`/sequence guards before
 * this runs, so the terminal folds exactly once. Reads the D7 payload keys
 * `{ message_id, finish_reason: 'cancelled', error_summary }`.
 */
const applyAssistantTurnCancelled = (
  thread: ThreadState,
  event: ConversationEventEnvelope,
  seenEventIds: Set<string>,
  lastSeenSequence: number,
): ThreadState => {
  const rawMessageId = event.payload.message_id
  const messageId = typeof rawMessageId === 'string' ? rawMessageId : null
  const messages =
    messageId === null
      ? thread.messages
      : thread.messages.map((m) =>
          m.id === messageId ? { ...m, status: 'cancelled' as const } : m,
        )
  return {
    ...thread,
    seenEventIds,
    lastSeenSequence,
    messages,
    runStatus: 'cancelled',
    activeTurnId: null,
  }
}

/**
 * Thread switch — does NOT discard the prior thread's state
 * (background subscriptions continue to fold there). The UI reads
 * `state.threadsById[state.currentThreadId]` (handoff §4 case 3).
 */
export const setCurrentThread = (
  state: ConversationStoreState,
  threadId: string,
): ConversationStoreState => {
  return {
    ...ensureThread(state, threadId),
    currentThreadId: threadId,
  }
}

/**
 * User clicks Cancel before the terminal event arrives. The next
 * terminal event from the loop resolves the run state to
 * completed/failed (handoff §4 case 5).
 */
export const markCancelRequested = (
  state: ConversationStoreState,
  threadId: string,
): ConversationStoreState => {
  const withThread = ensureThread(state, threadId)
  const thread = withThread.threadsById[threadId]!
  if (thread.runStatus !== 'streaming') return withThread
  return {
    ...withThread,
    threadsById: {
      ...withThread.threadsById,
      [threadId]: { ...thread, runStatus: 'cancelling' },
    },
  }
}
