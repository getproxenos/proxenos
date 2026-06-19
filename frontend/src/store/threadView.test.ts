import { describe, expect, it } from 'vitest'

import {
  foldEvent,
  foldEvents,
  initialStoreState,
  markCancelRequested,
  markHydrated,
  setCurrentThread,
} from './reducer'
import { deriveThreadView } from './threadView'
import type { ConversationEventEnvelope } from './types'

/**
 * D6 — the chat surface's render branches, asserted off REAL reducer state
 * (built via the same folds the live/replay adapter uses) rather than
 * hand-rolled fixtures. `deriveThreadView` is the pure decision the component
 * switches on; these lock its honesty contract (loading vs empty vs FAILED vs
 * CANCELLED) so the visual layer can stay a dumb switch. Real-browser visual
 * nuance (banner placement, scroll, styling) is flagged for Beau's live check.
 */

const THREAD = 'aaaaaaaa-aaaa-7aaa-aaaa-aaaaaaaaaaaa'
const TURN_ID = 'cccccccc-cccc-7ccc-cccc-cccccccccccc'
const USER_MSG_ID = 'dddddddd-dddd-7ddd-dddd-dddddddddddd'
const ASSISTANT_MSG_ID = 'eeeeeeee-eeee-7eee-eeee-eeeeeeeeeeee'

const makeEvent = (
  partial: Partial<ConversationEventEnvelope> & {
    sequence: number
    type: ConversationEventEnvelope['type']
  },
): ConversationEventEnvelope => ({
  id: partial.id ?? `evt-${partial.sequence}`,
  sequence: partial.sequence,
  thread_id: partial.thread_id ?? THREAD,
  turn_id: partial.turn_id ?? null,
  type: partial.type,
  version: 1,
  actor_type: partial.actor_type ?? 'assistant',
  actor_id: partial.actor_id ?? null,
  occurred_at: partial.occurred_at ?? '2026-06-14T00:00:00Z',
  payload: partial.payload ?? {},
})

const userThenDelta: ConversationEventEnvelope[] = [
  makeEvent({
    id: 'evt-user',
    sequence: 1,
    type: 'user_message_submitted',
    actor_type: 'user',
    payload: { message_id: USER_MSG_ID, text: 'hello' },
  }),
  makeEvent({ id: 'evt-turn', sequence: 2, type: 'assistant_turn_created', turn_id: TURN_ID }),
  makeEvent({
    id: 'evt-delta',
    sequence: 3,
    type: 'assistant_content_delta',
    turn_id: TURN_ID,
    payload: { message_id: ASSISTANT_MSG_ID, part_index: 0, text: 'partial answer' },
  }),
]

const threadFrom = (
  events: ConversationEventEnvelope[],
  ...mutate: ((
    state: ReturnType<typeof initialStoreState>,
  ) => ReturnType<typeof initialStoreState>)[]
) => {
  let state = foldEvents(initialStoreState(), events)
  for (const fn of mutate) state = fn(state)
  return state.threadsById[THREAD]
}

describe('deriveThreadView — loading vs empty', () => {
  it('reads a missing thread as loading (focused before its state materializes)', () => {
    expect(deriveThreadView(undefined)).toEqual({ status: 'loading' })
    expect(deriveThreadView(null)).toEqual({ status: 'loading' })
  })

  it('a freshly-selected, un-hydrated empty thread is loading — never a silent stall', () => {
    const thread = setCurrentThread(initialStoreState(), THREAD).threadsById[THREAD]
    expect(thread!.hydrated).toBe(false)
    expect(deriveThreadView(thread)).toEqual({ status: 'loading' })
  })

  it('an empty thread whose replay has settled is empty (markHydrated flips it)', () => {
    const thread = setCurrentThread(initialStoreState(), THREAD).threadsById[THREAD]
    const hydrated = markHydrated(setCurrentThread(initialStoreState(), THREAD), THREAD)
      .threadsById[THREAD]
    expect(deriveThreadView(thread)).toEqual({ status: 'loading' })
    expect(deriveThreadView(hydrated)).toEqual({ status: 'empty' })
  })

  it('messages present → ready regardless of hydration flag (live deltas beat replay)', () => {
    const thread = threadFrom(userThenDelta)
    expect(thread!.hydrated).toBe(false)
    expect(deriveThreadView(thread)).toEqual({ status: 'ready', banner: { kind: 'none' } })
  })
})

describe('deriveThreadView — FAILED', () => {
  it('surfaces a failure banner with the host error_summary, partial text retained', () => {
    const thread = threadFrom([
      ...userThenDelta,
      makeEvent({
        id: 'evt-failed',
        sequence: 4,
        type: 'assistant_turn_failed',
        turn_id: TURN_ID,
        payload: {
          message_id: ASSISTANT_MSG_ID,
          finish_reason: 'platform_error',
          error_summary: 'PlatformException: timed out',
        },
      }),
    ])
    expect(thread!.runStatus).toBe('failed')
    expect(thread!.messages[1]!.text).toBe('partial answer')
    expect(deriveThreadView(thread)).toEqual({
      status: 'ready',
      banner: { kind: 'failed', errorSummary: 'PlatformException: timed out' },
    })
  })

  it('failed banner tolerates a null error_summary (no detail line to render)', () => {
    const thread = threadFrom([
      ...userThenDelta,
      makeEvent({
        id: 'evt-failed-bare',
        sequence: 4,
        type: 'assistant_turn_failed',
        turn_id: TURN_ID,
        payload: { message_id: ASSISTANT_MSG_ID, finish_reason: 'platform_error' },
      }),
    ])
    expect(deriveThreadView(thread)).toEqual({
      status: 'ready',
      banner: { kind: 'failed', errorSummary: null },
    })
  })
})

describe('deriveThreadView — CANCELLED (not failed)', () => {
  it('renders a stopped banner with NO errorSummary, partial text preserved', () => {
    const thread = threadFrom([
      ...userThenDelta,
      makeEvent({
        id: 'evt-cancelled',
        sequence: 4,
        type: 'assistant_turn_cancelled',
        turn_id: TURN_ID,
        payload: { message_id: ASSISTANT_MSG_ID, finish_reason: 'cancelled', error_summary: '' },
      }),
    ])
    expect(thread!.runStatus).toBe('cancelled')
    expect(thread!.errorSummary).toBeNull()
    expect(thread!.messages[1]!.text).toBe('partial answer')
    expect(thread!.messages[1]!.status).toBe('cancelled')
    // The honest contract: cancelled routes to its own banner, NOT 'failed'.
    expect(deriveThreadView(thread)).toEqual({ status: 'ready', banner: { kind: 'cancelled' } })
  })

  it('shows the transient "cancelling" hint after Stop, before the terminal event', () => {
    const thread = threadFrom(userThenDelta, (s) => markCancelRequested(s, THREAD))
    expect(thread!.runStatus).toBe('cancelling')
    expect(deriveThreadView(thread)).toEqual({ status: 'ready', banner: { kind: 'cancelling' } })
  })
})

describe('deriveThreadView — streaming / completed have no banner', () => {
  it('a mid-stream thread is ready with no banner', () => {
    const thread = threadFrom(userThenDelta)
    expect(thread!.runStatus).toBe('streaming')
    expect(deriveThreadView(thread)).toEqual({ status: 'ready', banner: { kind: 'none' } })
  })

  it('a completed thread is ready with no banner', () => {
    const thread = threadFrom([
      ...userThenDelta,
      makeEvent({
        id: 'evt-completed',
        sequence: 4,
        type: 'assistant_turn_completed',
        turn_id: TURN_ID,
        payload: { message_id: ASSISTANT_MSG_ID, finish_reason: 'stop' },
      }),
    ])
    expect(thread!.runStatus).toBe('completed')
    expect(deriveThreadView(thread)).toEqual({ status: 'ready', banner: { kind: 'none' } })
  })
})

describe('markHydrated (reducer helper used by the shell)', () => {
  it('is reference-stable once already hydrated (no needless store churn)', () => {
    const once = markHydrated(setCurrentThread(initialStoreState(), THREAD), THREAD)
    const twice = markHydrated(once, THREAD)
    expect(twice).toBe(once)
  })

  it('preserves hydrated across a subsequent fold', () => {
    let state = markHydrated(setCurrentThread(initialStoreState(), THREAD), THREAD)
    state = foldEvent(state, userThenDelta[0]!)
    expect(state.threadsById[THREAD]!.hydrated).toBe(true)
  })
})
