// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest'
import { cleanup, fireEvent, render, screen } from '@testing-library/react'
import { MemoryRouter, useLocation } from 'react-router-dom'
import type { ComponentProps } from 'react'

import { ThreadSidebar } from './ThreadSidebar'
import type { ThreadListItem } from '../store/threadList'

/**
 * ThreadList sidebar (D3). Switching/new are route-driven (D1): the component
 * navigates and lets `ThreadRoute` select. Rename/archive delegate to the
 * shell callbacks (the actual POSTs + reconcile live in `App.tsx`).
 */

const item = (over: Partial<ThreadListItem>): ThreadListItem => ({
  id: over.id ?? 'id-1',
  title: over.title ?? null,
  status: over.status ?? 'active',
  updatedAt: over.updatedAt ?? '2026-06-17T12:00:00+00:00',
})

const LocationDisplay = () => {
  const location = useLocation()
  return <span data-testid="location">{location.pathname}</span>
}

const renderSidebar = (props: Partial<ComponentProps<typeof ThreadSidebar>> = {}) =>
  render(
    <MemoryRouter initialEntries={['/']}>
      <ThreadSidebar
        threads={props.threads ?? [item({ id: 'id-1', title: 'First thread' })]}
        currentThreadId={props.currentThreadId ?? null}
        onRename={props.onRename ?? vi.fn()}
        onArchive={props.onArchive ?? vi.fn()}
      />
      <LocationDisplay />
    </MemoryRouter>,
  )

describe('ThreadSidebar', () => {
  afterEach(cleanup)

  it('renders the active threads with a display title fallback', () => {
    renderSidebar({
      threads: [item({ id: 'id-1', title: 'Named thread' }), item({ id: 'id-2', title: null })],
    })

    expect(screen.getByText('Named thread')).toBeTruthy()
    expect(screen.getByText('Untitled thread')).toBeTruthy()
  })

  it('shows an empty placeholder when there are no threads', () => {
    renderSidebar({ threads: [] })
    expect(screen.getByText('No threads yet')).toBeTruthy()
  })

  it('routes to a minted UUID thread when New is clicked (decision 9)', () => {
    renderSidebar({ threads: [] })

    fireEvent.click(screen.getByRole('button', { name: 'New' }))

    const path = screen.getByTestId('location').textContent ?? ''
    expect(path).toMatch(/^\/threads\/[0-9a-f-]{36}$/)
  })

  it('routes to a thread when its switch button is clicked', () => {
    renderSidebar({ threads: [item({ id: 'switch-target', title: 'Go here' })] })

    fireEvent.click(screen.getByRole('button', { name: 'Go here' }))

    expect(screen.getByTestId('location').textContent).toBe('/threads/switch-target')
  })

  it('calls onArchive with the thread id', () => {
    const onArchive = vi.fn()
    renderSidebar({ threads: [item({ id: 'to-archive', title: 'Bye' })], onArchive })

    fireEvent.click(screen.getByRole('button', { name: 'Archive thread' }))

    expect(onArchive).toHaveBeenCalledWith('to-archive')
  })

  it('renames inline and calls onRename with the trimmed title', async () => {
    const onRename = vi.fn().mockResolvedValue(undefined)
    renderSidebar({ threads: [item({ id: 'to-rename', title: 'Old' })], onRename })

    fireEvent.click(screen.getByRole('button', { name: 'Rename thread' }))
    const input = screen.getByRole('textbox', { name: 'Rename thread' })
    fireEvent.change(input, { target: { value: '  New title  ' } })
    fireEvent.keyDown(input, { key: 'Enter' })

    expect(onRename).toHaveBeenCalledWith('to-rename', 'New title')
  })

  it('rejects an over-long title client-side without calling onRename', () => {
    const onRename = vi.fn()
    renderSidebar({ threads: [item({ id: 'to-rename', title: 'Old' })], onRename })

    fireEvent.click(screen.getByRole('button', { name: 'Rename thread' }))
    const input = screen.getByRole('textbox', { name: 'Rename thread' })
    fireEvent.change(input, { target: { value: 'x'.repeat(201) } })
    fireEvent.keyDown(input, { key: 'Enter' })

    expect(onRename).not.toHaveBeenCalled()
    expect(screen.getByRole('alert').textContent).toContain('200 characters or fewer')
  })
})
