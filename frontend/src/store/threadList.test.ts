import { afterEach, describe, expect, it, vi } from 'vitest'

import { mapThreadListResponse, newThreadId, removeThread, threadDisplayTitle } from './threadList'
import type { ThreadListItemResponse } from './transport'

/**
 * External thread-list adapter (D3). Pure mapping + reconcile logic over the
 * `GET /api/threads` wire shape (D2), unit-testable without DOM/fetch.
 */

const row = (over: Partial<ThreadListItemResponse>): ThreadListItemResponse => ({
  id: over.id ?? 'aaaaaaaa-aaaa-7aaa-aaaa-aaaaaaaaaaaa',
  title: over.title ?? null,
  status: over.status ?? 'active',
  updated_at: over.updated_at ?? '2026-06-17T12:00:00+00:00',
})

describe('mapThreadListResponse', () => {
  it('maps wire rows into list items, snake_case → camelCase', () => {
    const items = mapThreadListResponse([
      row({
        id: 'id-1',
        title: 'Roadmap chat',
        status: 'active',
        updated_at: '2026-06-17T09:00:00+00:00',
      }),
    ])

    expect(items).toEqual([
      {
        id: 'id-1',
        title: 'Roadmap chat',
        status: 'active',
        updatedAt: '2026-06-17T09:00:00+00:00',
      },
    ])
  })

  it('collapses a missing, null, or blank title to null', () => {
    const items = mapThreadListResponse([
      row({ id: 'null-title', title: null }),
      row({ id: 'blank-title', title: '   ' }),
      row({ id: 'real-title', title: 'Kept' }),
    ])

    expect(items.map((i) => i.title)).toEqual([null, null, 'Kept'])
  })

  it('preserves the server ordering (ordering is server-authoritative)', () => {
    const items = mapThreadListResponse([
      row({ id: 'newest', updated_at: '2026-06-17T12:00:00+00:00' }),
      row({ id: 'older', updated_at: '2026-06-16T12:00:00+00:00' }),
    ])

    expect(items.map((i) => i.id)).toEqual(['newest', 'older'])
  })
})

describe('threadDisplayTitle', () => {
  it('falls back to a placeholder when the title is still null', () => {
    expect(threadDisplayTitle({ id: 'x', title: null, status: 'active', updatedAt: '' })).toBe(
      'Untitled thread',
    )
    expect(threadDisplayTitle({ id: 'x', title: 'Named', status: 'active', updatedAt: '' })).toBe(
      'Named',
    )
  })
})

describe('removeThread (optimistic archive)', () => {
  it('drops the archived id and leaves the rest untouched', () => {
    const items = mapThreadListResponse([
      row({ id: 'keep-1' }),
      row({ id: 'gone' }),
      row({ id: 'keep-2' }),
    ])

    expect(removeThread(items, 'gone').map((i) => i.id)).toEqual(['keep-1', 'keep-2'])
  })

  it('is a no-op when the id is not present', () => {
    const items = mapThreadListResponse([row({ id: 'keep-1' })])
    expect(removeThread(items, 'missing')).toEqual(items)
  })
})

describe('new-thread id flow (decision 9)', () => {
  afterEach(() => vi.restoreAllMocks())

  it('mints a client-side UUID for a new thread', () => {
    vi.spyOn(crypto, 'randomUUID').mockReturnValue('11111111-1111-4111-8111-111111111111')
    expect(newThreadId()).toBe('11111111-1111-4111-8111-111111111111')
  })

  it('a minted thread is absent until its first turn re-fetches the list', () => {
    vi.spyOn(crypto, 'randomUUID').mockReturnValue('22222222-2222-4222-8222-222222222222')
    const minted = newThreadId()

    // Before the first message there is no server row, so the minted thread
    // is not in the list the user routed into.
    const before = mapThreadListResponse([row({ id: 'existing', title: 'Old thread' })])
    expect(before.some((i) => i.id === minted)).toBe(false)

    // After the first message folds (lazy create) the next GET /api/threads
    // returns it — title still null until D4's auto-titler runs.
    const after = mapThreadListResponse([
      row({ id: minted, title: null, updated_at: '2026-06-17T13:00:00+00:00' }),
      row({ id: 'existing', title: 'Old thread', updated_at: '2026-06-17T09:00:00+00:00' }),
    ])
    const reconciled = after.find((i) => i.id === minted)
    expect(reconciled).toBeDefined()
    expect(reconciled!.title).toBeNull()
    expect(threadDisplayTitle(reconciled!)).toBe('Untitled thread')
  })
})
