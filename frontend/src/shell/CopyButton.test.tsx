// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest'
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'

import { CopyButton } from './CopyButton'

/**
 * D5 copy-button feedback toggle. The actual clipboard write needs a real
 * browser (flagged for Beau's live check); here the clipboard is stubbed and we
 * assert the pure idle → "Copied" → idle UI transition.
 */
afterEach(() => {
  cleanup()
  vi.restoreAllMocks()
})

describe('CopyButton', () => {
  it('writes the value and flips to "Copied", then reverts after the duration', async () => {
    const writeText = vi.fn().mockResolvedValue(undefined)
    vi.stubGlobal('navigator', { clipboard: { writeText } })

    render(<CopyButton value="payload" label="Copy code" copiedDuration={50} />)
    const button = screen.getByRole('button', { name: 'Copy code' })
    expect(button.textContent).toBe('Copy code')

    fireEvent.click(button)
    await waitFor(() => expect(writeText).toHaveBeenCalledWith('payload'))
    await waitFor(() => expect(button.textContent).toBe('Copied'))
    expect(button.getAttribute('data-copied')).toBe('true')

    // copiedDuration elapses → reverts to idle.
    await waitFor(() => expect(button.textContent).toBe('Copy code'))
  })

  it('stays idle when the clipboard rejects', async () => {
    const writeText = vi.fn().mockRejectedValue(new Error('denied'))
    vi.stubGlobal('navigator', { clipboard: { writeText } })

    render(<CopyButton value="payload" label="Copy" />)
    const button = screen.getByRole('button', { name: 'Copy' })
    fireEvent.click(button)

    await waitFor(() => expect(writeText).toHaveBeenCalled())
    expect(button.textContent).toBe('Copy')
  })
})
