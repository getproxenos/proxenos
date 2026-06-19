import { describe, expect, it } from 'vitest'

import {
  foldEvent,
  foldEvents,
  initialStoreState,
  initialThreadState,
  markCancelRequested,
  setCurrentThread,
} from './reducer'
import type { ConversationEventEnvelope } from './types'

/**
 * Reducer tests are the verifiable core of prereq 4 (handoff §4
 * "These reconciliation tests ARE the verifiable core of #4"). They
 * exercise the live + replay normalization contract end-to-end:
 *  - case 1: reconnect after hidden tab → replay races live (`testReplayThenLiveConverges`).
 *  - case 2: missed live + replay race → duplicate dedupes (`testLiveAndReplayDeliveriesFoldOnce`).
 *  - case 3: thread switch during stream → background folds keep working (`testThreadSwitchPreservesBackgroundFolds`).
 *  - case 4: duplicate cumulative-replace deltas → replace, never append (`testDuplicateDeltaConverges`).
 *  - case 5: cancel before terminal → cancelling sticks until terminal (`testCancelRequestedHeldUntilTerminal`).
 *  - out-of-order delta arrival → sorted by sequence before fold.
 */

const TENANT_THREAD_A = 'aaaaaaaa-aaaa-7aaa-aaaa-aaaaaaaaaaaa'
const TENANT_THREAD_B = 'bbbbbbbb-bbbb-7bbb-bbbb-bbbbbbbbbbbb'
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
  thread_id: partial.thread_id ?? TENANT_THREAD_A,
  turn_id: partial.turn_id ?? null,
  type: partial.type,
  version: 1,
  actor_type: partial.actor_type ?? 'assistant',
  actor_id: partial.actor_id ?? null,
  occurred_at: partial.occurred_at ?? '2026-06-14T00:00:00Z',
  payload: partial.payload ?? {},
})

const canonicalTurn = (threadId: string = TENANT_THREAD_A): ConversationEventEnvelope[] => [
  makeEvent({
    id: 'evt-user',
    sequence: 1,
    type: 'user_message_submitted',
    actor_type: 'user',
    thread_id: threadId,
    payload: { message_id: USER_MSG_ID, text: 'hello' },
  }),
  makeEvent({
    id: 'evt-turn-created',
    sequence: 2,
    type: 'assistant_turn_created',
    turn_id: TURN_ID,
    thread_id: threadId,
  }),
  makeEvent({
    id: 'evt-delta',
    sequence: 3,
    type: 'assistant_content_delta',
    turn_id: TURN_ID,
    thread_id: threadId,
    payload: { message_id: ASSISTANT_MSG_ID, part_index: 0, text: 'hi back' },
  }),
  makeEvent({
    id: 'evt-completed',
    sequence: 4,
    type: 'assistant_turn_completed',
    turn_id: TURN_ID,
    thread_id: threadId,
    payload: { message_id: ASSISTANT_MSG_ID, finish_reason: 'stop' },
  }),
]

describe('foldEvent (happy path)', () => {
  it('reduces a canonical turn into ordered user/assistant messages', () => {
    const state = foldEvents(initialStoreState(), canonicalTurn())
    const thread = state.threadsById[TENANT_THREAD_A]!

    expect(thread.lastSeenSequence).toBe(4)
    expect(thread.runStatus).toBe('completed')
    expect(thread.activeTurnId).toBeNull()
    expect(thread.messages.map((m) => ({ role: m.role, text: m.text, status: m.status }))).toEqual([
      { role: 'user', text: 'hello', status: 'complete' },
      { role: 'assistant', text: 'hi back', status: 'complete' },
    ])
  })
})

describe('reconciliation case 2 — live + replay race', () => {
  it('folds duplicate envelopes once (dedupe by event id)', () => {
    const events = canonicalTurn()
    const state = foldEvents(initialStoreState(), [...events, ...events])
    const thread = state.threadsById[TENANT_THREAD_A]!

    expect(thread.messages).toHaveLength(2)
    expect(thread.messages[1]!.text).toBe('hi back')
  })

  it('treats lower-sequence later arrival as duplicate', () => {
    const events = canonicalTurn()
    // First fold all four.
    let state = foldEvents(initialStoreState(), events)
    // A duplicate live frame with the SAME envelope arriving after we
    // already have seq=4 must not regress.
    state = foldEvent(state, events[2]!)
    const thread = state.threadsById[TENANT_THREAD_A]!

    expect(thread.lastSeenSequence).toBe(4)
    expect(thread.runStatus).toBe('completed')
  })
})

describe('reconciliation case 1 — reconnect then replay', () => {
  it('cursor-after-lastSeen replay fills gaps without double-folding live events already seen', () => {
    const events = canonicalTurn()
    // Simulate: live delivered events 1 + 2 before the tab went hidden.
    let state = foldEvents(initialStoreState(), [events[0]!, events[1]!])
    expect(state.threadsById[TENANT_THREAD_A]!.lastSeenSequence).toBe(2)

    // On resume the adapter calls /events?after=2 and gets [3, 4].
    state = foldEvents(state, [events[2]!, events[3]!])
    // Mercure also delivers a duplicate of event 2 mid-replay.
    state = foldEvent(state, events[1]!)

    const thread = state.threadsById[TENANT_THREAD_A]!
    expect(thread.messages).toHaveLength(2)
    expect(thread.messages[1]!.text).toBe('hi back')
    expect(thread.runStatus).toBe('completed')
  })
})

describe('reconciliation case 4 — duplicate cumulative-replace delta', () => {
  it('replaces (does not append) when the same delta event is folded twice', () => {
    const delta = makeEvent({
      id: 'evt-delta-only',
      sequence: 3,
      type: 'assistant_content_delta',
      turn_id: TURN_ID,
      payload: { message_id: ASSISTANT_MSG_ID, part_index: 0, text: 'first run' },
    })
    let state = initialStoreState()
    // Bootstrap with prior events so the assistant message can attach.
    state = foldEvents(state, [canonicalTurn()[0]!, canonicalTurn()[1]!])
    state = foldEvent(state, delta)
    expect(state.threadsById[TENANT_THREAD_A]!.messages[1]!.text).toBe('first run')

    // Same id, same payload arrives again — must not duplicate or append.
    state = foldEvent(state, delta)
    const thread = state.threadsById[TENANT_THREAD_A]!
    expect(thread.messages).toHaveLength(2)
    expect(thread.messages[1]!.text).toBe('first run')
  })

  it('cumulative-replace converges when growing deltas arrive out of sequence order', () => {
    const setup = foldEvents(initialStoreState(), [canonicalTurn()[0]!, canonicalTurn()[1]!])
    // Three growing deltas at seq 3, 4, 5; deliver them in reverse.
    const deltas = [3, 4, 5].map((seq, i) =>
      makeEvent({
        id: `evt-delta-${seq}`,
        sequence: seq,
        type: 'assistant_content_delta',
        turn_id: TURN_ID,
        payload: {
          message_id: ASSISTANT_MSG_ID,
          part_index: 0,
          text: 'abcdefghi'.slice(0, 3 * (i + 1)),
        },
      }),
    )
    const state = foldEvents(setup, [deltas[2]!, deltas[0]!, deltas[1]!])
    const thread = state.threadsById[TENANT_THREAD_A]!
    expect(thread.messages[1]!.text).toBe('abcdefghi')
    expect(thread.lastSeenSequence).toBe(5)
  })
})

describe('reconciliation case 3 — thread switch during stream', () => {
  it('keeps both threads streaming independently and switches focus without touching either', () => {
    let state = initialStoreState()
    // Thread A: kick off a turn that hasn't completed.
    state = foldEvents(state, [
      canonicalTurn(TENANT_THREAD_A)[0]!,
      canonicalTurn(TENANT_THREAD_A)[1]!,
    ])
    expect(state.threadsById[TENANT_THREAD_A]!.runStatus).toBe('streaming')

    // Thread B: deliver a full canonical turn.
    state = foldEvents(state, canonicalTurn(TENANT_THREAD_B))
    expect(state.threadsById[TENANT_THREAD_B]!.runStatus).toBe('completed')

    // Switch UI focus to B while a delta lands on A in the background.
    state = setCurrentThread(state, TENANT_THREAD_B)
    state = foldEvent(
      state,
      makeEvent({
        id: 'evt-delta-A',
        sequence: 3,
        type: 'assistant_content_delta',
        turn_id: TURN_ID,
        thread_id: TENANT_THREAD_A,
        payload: { message_id: ASSISTANT_MSG_ID, part_index: 0, text: 'background A' },
      }),
    )

    expect(state.currentThreadId).toBe(TENANT_THREAD_B)
    expect(state.threadsById[TENANT_THREAD_A]!.messages[1]!.text).toBe('background A')
    expect(state.threadsById[TENANT_THREAD_A]!.runStatus).toBe('streaming')
    // Thread B is unchanged by the A delivery.
    expect(state.threadsById[TENANT_THREAD_B]!.runStatus).toBe('completed')
  })
})

describe('reconciliation case 5 — cancel before terminal', () => {
  it('cancelling sticks until the loop appends a terminal event', () => {
    let state = foldEvents(initialStoreState(), [canonicalTurn()[0]!, canonicalTurn()[1]!])
    expect(state.threadsById[TENANT_THREAD_A]!.runStatus).toBe('streaming')

    state = markCancelRequested(state, TENANT_THREAD_A)
    expect(state.threadsById[TENANT_THREAD_A]!.runStatus).toBe('cancelling')

    // A mid-flight delta arriving while cancel is pending stays
    // 'cancelling' — it must NOT regress back to 'streaming'.
    state = foldEvent(state, canonicalTurn()[2]!)
    expect(state.threadsById[TENANT_THREAD_A]!.runStatus).toBe('cancelling')

    // The terminal event (completed in this branch; ADR-025 sketches
    // assistant_turn_cancelled but the host only emits the three
    // terminals it currently has) resolves the run.
    state = foldEvent(state, canonicalTurn()[3]!)
    expect(state.threadsById[TENANT_THREAD_A]!.runStatus).toBe('completed')
  })

  it('markCancelRequested is a no-op when there is no active stream', () => {
    const state = markCancelRequested(initialStoreState(), TENANT_THREAD_A)
    expect(state.threadsById[TENANT_THREAD_A]!.runStatus).toBe('idle')
  })
})

describe('assistant_turn_failed projection', () => {
  it('moves an in-flight message to failed and records error_summary', () => {
    let state = foldEvents(initialStoreState(), [
      canonicalTurn()[0]!,
      canonicalTurn()[1]!,
      canonicalTurn()[2]!,
    ])
    state = foldEvent(
      state,
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
    )
    const thread = state.threadsById[TENANT_THREAD_A]!
    expect(thread.runStatus).toBe('failed')
    expect(thread.messages[1]!.status).toBe('failed')
    expect(thread.errorSummary).toBe('PlatformException: timed out')
  })
})

describe('assistant_turn_cancelled projection (D8 / handoff §4 case 5)', () => {
  const cancelEvent = (id = 'evt-cancelled'): ConversationEventEnvelope =>
    makeEvent({
      id,
      sequence: 4,
      type: 'assistant_turn_cancelled',
      turn_id: TURN_ID,
      payload: { message_id: ASSISTANT_MSG_ID, finish_reason: 'cancelled', error_summary: '' },
    })

  it('settles a cancel-before-terminal run onto the cancelled event', () => {
    // user + turn-created + a delta → an in-flight assistant message.
    let state = foldEvents(initialStoreState(), [
      canonicalTurn()[0]!,
      canonicalTurn()[1]!,
      canonicalTurn()[2]!,
    ])
    expect(state.threadsById[TENANT_THREAD_A]!.runStatus).toBe('streaming')

    // User clicks Stop before any terminal arrives.
    state = markCancelRequested(state, TENANT_THREAD_A)
    expect(state.threadsById[TENANT_THREAD_A]!.runStatus).toBe('cancelling')

    // The loop's terminal cancelled event settles the held run.
    state = foldEvent(state, cancelEvent())
    const thread = state.threadsById[TENANT_THREAD_A]!
    expect(thread.runStatus).toBe('cancelled')
    expect(thread.activeTurnId).toBeNull()
    // The partial assistant text is preserved, marked stopped (not failed).
    expect(thread.messages[1]!.text).toBe('hi back')
    expect(thread.messages[1]!.status).toBe('cancelled')
    // A cancel is not an error — errorSummary stays clear.
    expect(thread.errorSummary).toBeNull()
  })

  it('settles even with no assistant delta (message_id null → no message mutated)', () => {
    let state = foldEvents(initialStoreState(), [canonicalTurn()[0]!, canonicalTurn()[1]!])
    state = markCancelRequested(state, TENANT_THREAD_A)
    state = foldEvent(
      state,
      makeEvent({
        id: 'evt-cancelled-nodelta',
        sequence: 4,
        type: 'assistant_turn_cancelled',
        turn_id: TURN_ID,
        payload: { message_id: null, finish_reason: 'cancelled', error_summary: '' },
      }),
    )
    const thread = state.threadsById[TENANT_THREAD_A]!
    expect(thread.runStatus).toBe('cancelled')
    expect(thread.activeTurnId).toBeNull()
    // Only the user message exists; nothing to mark.
    expect(thread.messages).toHaveLength(1)
    expect(thread.messages[0]!.role).toBe('user')
  })

  it('folds a duplicate / late cancelled event exactly once (idempotent)', () => {
    let state = foldEvents(initialStoreState(), [
      canonicalTurn()[0]!,
      canonicalTurn()[1]!,
      canonicalTurn()[2]!,
    ])
    state = foldEvent(state, cancelEvent())
    const settled = state.threadsById[TENANT_THREAD_A]!
    expect(settled.runStatus).toBe('cancelled')
    expect(settled.messages[1]!.status).toBe('cancelled')

    // Same envelope (same id) arriving again — live+replay race, or a late
    // re-delivery — must not re-fold or regress the settled run.
    state = foldEvent(state, cancelEvent())
    const after = state.threadsById[TENANT_THREAD_A]!
    expect(after.runStatus).toBe('cancelled')
    expect(after.messages).toHaveLength(2)
    expect(after.messages[1]!.status).toBe('cancelled')
    // State is reference-stable on the no-op re-fold (dedup short-circuits).
    expect(after).toBe(settled)
  })
})

describe('foldEvent (thread_system_prompt_set, D10)', () => {
  const setEvent = (over: Partial<ConversationEventEnvelope> & { sequence: number }) =>
    makeEvent({
      type: 'thread_system_prompt_set',
      actor_type: 'user',
      ...over,
    })

  it('projects the override onto systemPrompt (the editor loads it from here)', () => {
    const state = foldEvent(
      initialStoreState(),
      setEvent({ id: 'evt-sp-1', sequence: 1, payload: { system_prompt: 'Be terse.' } }),
    )
    expect(state.threadsById[TENANT_THREAD_A]!.systemPrompt).toBe('Be terse.')
  })

  it('clears the override on a null payload', () => {
    let state = foldEvent(
      initialStoreState(),
      setEvent({ id: 'evt-sp-1', sequence: 1, payload: { system_prompt: 'Be terse.' } }),
    )
    state = foldEvent(
      state,
      setEvent({ id: 'evt-sp-2', sequence: 2, payload: { system_prompt: null } }),
    )
    expect(state.threadsById[TENANT_THREAD_A]!.systemPrompt).toBeNull()
  })

  it('does not disturb message/run state (additive to the streaming path)', () => {
    let state = foldEvents(initialStoreState(), canonicalTurn())
    state = foldEvent(
      state,
      setEvent({ id: 'evt-sp-1', sequence: 5, payload: { system_prompt: 'persona' } }),
    )
    const thread = state.threadsById[TENANT_THREAD_A]!
    expect(thread.systemPrompt).toBe('persona')
    expect(thread.runStatus).toBe('completed')
    expect(thread.messages).toHaveLength(2)
  })

  it('seeds systemPrompt null on a fresh thread', () => {
    expect(initialThreadState(TENANT_THREAD_A).systemPrompt).toBeNull()
  })
})

describe('initial state helpers', () => {
  it('seeds an empty thread state', () => {
    const t = initialThreadState(TENANT_THREAD_A)
    expect(t.lastSeenSequence).toBe(0)
    expect(t.seenEventIds.size).toBe(0)
    expect(t.messages).toEqual([])
    expect(t.runStatus).toBe('idle')
  })
})

describe('thread-list lifecycle events over the live envelope', () => {
  // Regression: thread_renamed / thread_archived ride the same Mercure/replay
  // envelope as message events. Before they were added to the union + switch,
  // they fell through foldIntoThread() and wrote `undefined` into
  // threadsById[thread_id], corrupting the active thread the first time a new
  // thread auto-titled via thread_renamed.
  it('folds thread_renamed without corrupting the per-thread store', () => {
    let state = foldEvents(initialStoreState(), canonicalTurn())

    state = foldEvent(
      state,
      makeEvent({
        id: 'evt-renamed',
        sequence: 5,
        type: 'thread_renamed',
        payload: { title: 'Auto-titled thread' },
      }),
    )

    const thread = state.threadsById[TENANT_THREAD_A]!
    // Still a valid ThreadState (not undefined) and cursor advanced.
    expect(thread).toBeDefined()
    expect(thread.threadId).toBe(TENANT_THREAD_A)
    expect(thread.lastSeenSequence).toBe(5)
    expect(thread.seenEventIds.has('evt-renamed')).toBe(true)
    // Message/run state untouched — the title lives in the thread-list adapter.
    expect(thread.messages).toHaveLength(2)
    expect(thread.runStatus).toBe('completed')
  })

  it('folds thread_archived as a cursor-advancing no-op', () => {
    let state = foldEvents(initialStoreState(), canonicalTurn())

    state = foldEvent(
      state,
      makeEvent({ id: 'evt-archived', sequence: 5, type: 'thread_archived' }),
    )

    const thread = state.threadsById[TENANT_THREAD_A]!
    expect(thread).toBeDefined()
    expect(thread.lastSeenSequence).toBe(5)
    expect(thread.messages).toHaveLength(2)
  })
})
