// @vitest-environment jsdom
import { afterEach, describe, expect, it } from 'vitest'
import { cleanup, render, screen } from '@testing-library/react'

import { ThreadPlaceholder, ThreadStatusBanner } from './ThreadStatus'

/**
 * D6 — render the honest status surfaces in isolation (no assistant-ui runtime
 * needed). These assert the markup branches; real-browser visual polish
 * (placement, spacing, colour legibility light/dark) is flagged for Beau's
 * live check.
 */
afterEach(cleanup)

describe('ThreadPlaceholder', () => {
  it('loading is a busy status, not a stall', () => {
    render(<ThreadPlaceholder status="loading" />)
    const el = screen.getByRole('status')
    expect(el.getAttribute('aria-busy')).toBe('true')
    expect(el.textContent).toContain('Loading')
  })

  it('empty invites a first message', () => {
    render(<ThreadPlaceholder status="empty" />)
    const el = screen.getByRole('status')
    expect(el.getAttribute('aria-busy')).toBeNull()
    expect(el.textContent).toContain('No messages yet')
    expect(el.textContent).toContain('Send a message')
  })
})

describe('ThreadStatusBanner', () => {
  it('renders nothing for the none banner', () => {
    const { container } = render(<ThreadStatusBanner banner={{ kind: 'none' }} />)
    expect(container.firstChild).toBeNull()
  })

  it('FAILED is an alert carrying the error summary', () => {
    render(<ThreadStatusBanner banner={{ kind: 'failed', errorSummary: 'boom: timed out' }} />)
    const el = screen.getByRole('alert')
    expect(el.className).toContain('thread-status-failed')
    expect(el.textContent).toContain('error')
    expect(el.textContent).toContain('boom: timed out')
  })

  it('FAILED with no summary omits the detail line', () => {
    render(<ThreadStatusBanner banner={{ kind: 'failed', errorSummary: null }} />)
    const el = screen.getByRole('alert')
    expect(el.querySelector('.thread-status-detail')).toBeNull()
  })

  it('CANCELLED is a neutral status — not an alert, not failure styling', () => {
    render(<ThreadStatusBanner banner={{ kind: 'cancelled' }} />)
    expect(screen.queryByRole('alert')).toBeNull()
    const el = screen.getByRole('status')
    expect(el.className).toContain('thread-status-cancelled')
    expect(el.className).not.toContain('thread-status-failed')
    expect(el.textContent).toContain('stopped')
  })

  it('cancelling is a busy "Stopping…" hint', () => {
    render(<ThreadStatusBanner banner={{ kind: 'cancelling' }} />)
    const el = screen.getByRole('status')
    expect(el.getAttribute('aria-busy')).toBe('true')
    expect(el.textContent).toContain('Stopping')
  })
})
