import { describe, expect, it } from 'vitest'

import { foldEvents, initialStoreState, setCurrentThread } from './reducer'
import type { ConversationEventEnvelope } from './types'

/**
 * Switch-preserves-prior-thread-state (D3). The sidebar switches threads by
 * routing to `/app/threads/:id`, which the shell turns into `setCurrentThread`
 * (route-driven selection, D1). This asserts the load-bearing invariant the
 * sidebar relies on: switching only moves `currentThreadId` — it never
 * discards the folded message state of the thread we navigated away from, so
 * switching back shows the prior conversation intact (handoff §4 case 3).
 */

const THREAD_A = 'aaaaaaaa-aaaa-7aaa-aaaa-aaaaaaaaaaaa'
const THREAD_B = 'bbbbbbbb-bbbb-7bbb-bbbb-bbbbbbbbbbbb'

const userMessage = (
  threadId: string,
  messageId: string,
  text: string,
): ConversationEventEnvelope => ({
  id: `evt-${messageId}`,
  sequence: 1,
  thread_id: threadId,
  turn_id: null,
  type: 'user_message_submitted',
  version: 1,
  actor_type: 'user',
  actor_id: null,
  occurred_at: '2026-06-17T00:00:00Z',
  payload: { message_id: messageId, text },
})

describe('thread switching', () => {
  it('preserves the prior thread state across a switch and back', () => {
    let state = initialStoreState()

    // Focus + populate thread A.
    state = setCurrentThread(state, THREAD_A)
    state = foldEvents(state, [userMessage(THREAD_A, 'msg-a', 'hello from A')])

    // Switch to B and populate it.
    state = setCurrentThread(state, THREAD_B)
    state = foldEvents(state, [userMessage(THREAD_B, 'msg-b', 'hello from B')])

    expect(state.currentThreadId).toBe(THREAD_B)
    // A's state survived the switch untouched.
    expect(state.threadsById[THREAD_A]?.messages.map((m) => m.text)).toEqual(['hello from A'])

    // Switch back to A — its conversation is intact, B's is still there too.
    state = setCurrentThread(state, THREAD_A)
    expect(state.currentThreadId).toBe(THREAD_A)
    expect(state.threadsById[THREAD_A]?.messages.map((m) => m.text)).toEqual(['hello from A'])
    expect(state.threadsById[THREAD_B]?.messages.map((m) => m.text)).toEqual(['hello from B'])
  })

  it('a background fold into the non-current thread does not touch the focused thread', () => {
    let state = initialStoreState()
    state = setCurrentThread(state, THREAD_A)
    state = setCurrentThread(state, THREAD_B) // now focused on B

    // A late Mercure delivery for A (the backgrounded thread) folds into A's
    // slot without disturbing B or the current selection.
    state = foldEvents(state, [userMessage(THREAD_A, 'late-a', 'late delivery')])

    expect(state.currentThreadId).toBe(THREAD_B)
    expect(state.threadsById[THREAD_A]?.messages.map((m) => m.text)).toEqual(['late delivery'])
    expect(state.threadsById[THREAD_B]?.messages ?? []).toEqual([])
  })
})
