// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest'
import { act, cleanup, render } from '@testing-library/react'
import { MemoryRouter, Route, Routes, createMemoryRouter, RouterProvider } from 'react-router-dom'

import { ThreadRoute } from './ThreadRoute'

/**
 * Router → currentThread wiring (D1). The `/app/threads/:id` route must drive
 * thread selection: mounting at a thread path selects that id once, and
 * navigating between thread ids re-selects without firing for unrelated
 * renders. `onSelect` is the shell's `setCurrentThread + subscribe + hydrate`.
 */
const renderAt = (path: string, onSelect: (id: string) => void) =>
  render(
    <MemoryRouter initialEntries={[path]}>
      <Routes>
        <Route index element={<span>empty</span>} />
        <Route
          path="threads/:id"
          element={
            <ThreadRoute onSelect={onSelect}>
              <span>surface</span>
            </ThreadRoute>
          }
        />
      </Routes>
    </MemoryRouter>,
  )

describe('ThreadRoute', () => {
  afterEach(cleanup)

  it('selects the thread id from the route on mount', () => {
    const onSelect = vi.fn()
    renderAt('/threads/abc-123', onSelect)

    expect(onSelect).toHaveBeenCalledTimes(1)
    expect(onSelect).toHaveBeenCalledWith('abc-123')
  })

  it('re-selects when navigating to a different thread id', async () => {
    const onSelect = vi.fn()
    const router = createMemoryRouter(
      [
        {
          path: 'threads/:id',
          element: (
            <ThreadRoute onSelect={onSelect}>
              <span>surface</span>
            </ThreadRoute>
          ),
        },
      ],
      { initialEntries: ['/threads/first'] },
    )
    render(<RouterProvider router={router} />)
    expect(onSelect).toHaveBeenLastCalledWith('first')

    await act(async () => {
      await router.navigate('/threads/second')
    })

    expect(onSelect).toHaveBeenLastCalledWith('second')
    expect(onSelect).toHaveBeenCalledTimes(2)
  })

  it('does not select a thread on the empty-state index route', () => {
    const onSelect = vi.fn()
    renderAt('/', onSelect)

    expect(onSelect).not.toHaveBeenCalled()
  })
})
