// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest'
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'

import { SystemPromptDialog } from './SystemPromptDialog'

/**
 * System-prompt editor (D10). One reusable dialog backs both the global default
 * (`GET/PUT /api/me/settings`) and the per-thread override (`PUT
 * .../system-prompt`). The transport is the injected `load`/`save` seam, so
 * these tests mock it directly: load-on-open shows the current value; Save
 * persists it; a blank edit clears to `null`.
 */
describe('SystemPromptDialog', () => {
  afterEach(cleanup)

  const renderDialog = (over: Partial<Parameters<typeof SystemPromptDialog>[0]> = {}) => {
    const load = over.load ?? vi.fn().mockResolvedValue(null)
    const save = over.save ?? vi.fn().mockResolvedValue(undefined)
    const onClose = over.onClose ?? vi.fn()
    render(
      <SystemPromptDialog
        title={over.title ?? 'Default system prompt'}
        description={over.description ?? 'desc'}
        load={load}
        save={save}
        onClose={onClose}
      />,
    )
    return { load, save, onClose }
  }

  const textarea = () => screen.getByRole('textbox') as HTMLTextAreaElement

  it('loads the current value into the textarea on open (global default)', async () => {
    const load = vi.fn().mockResolvedValue('Be concise.')
    renderDialog({ load })

    await waitFor(() => expect(textarea().value).toBe('Be concise.'))
    expect(load).toHaveBeenCalledTimes(1)
  })

  it('shows an empty textarea when there is no current value', async () => {
    renderDialog({ load: vi.fn().mockResolvedValue(null) })

    await waitFor(() => expect(textarea().disabled).toBe(false))
    expect(textarea().value).toBe('')
  })

  it('saves the edited value and closes (PUT on save)', async () => {
    const save = vi.fn().mockResolvedValue(undefined)
    const onClose = vi.fn()
    renderDialog({ load: vi.fn().mockResolvedValue('old'), save, onClose })

    await waitFor(() => expect(textarea().value).toBe('old'))
    fireEvent.change(textarea(), { target: { value: 'new prompt' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))

    await waitFor(() => expect(save).toHaveBeenCalledWith('new prompt'))
    await waitFor(() => expect(onClose).toHaveBeenCalledTimes(1))
  })

  it('clears the override when saved blank (per-thread blank → null)', async () => {
    const save = vi.fn().mockResolvedValue(undefined)
    renderDialog({ load: vi.fn().mockResolvedValue('an override'), save })

    await waitFor(() => expect(textarea().value).toBe('an override'))
    fireEvent.change(textarea(), { target: { value: '   ' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))

    await waitFor(() => expect(save).toHaveBeenCalledWith(null))
  })

  it('cancels without saving', async () => {
    const save = vi.fn()
    const onClose = vi.fn()
    renderDialog({ load: vi.fn().mockResolvedValue('x'), save, onClose })

    await waitFor(() => expect(textarea().value).toBe('x'))
    fireEvent.click(screen.getByRole('button', { name: 'Cancel' }))

    expect(save).not.toHaveBeenCalled()
    expect(onClose).toHaveBeenCalledTimes(1)
  })

  it('surfaces a save failure and stays open', async () => {
    const save = vi.fn().mockRejectedValue(new Error('boom'))
    const onClose = vi.fn()
    renderDialog({ load: vi.fn().mockResolvedValue(''), save, onClose })

    await waitFor(() => expect(textarea().disabled).toBe(false))
    fireEvent.change(textarea(), { target: { value: 'try' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save' }))

    await waitFor(() => expect(screen.getByRole('alert').textContent).toContain('Could not save'))
    expect(onClose).not.toHaveBeenCalled()
  })

  it('surfaces a load failure', async () => {
    renderDialog({ load: vi.fn().mockRejectedValue(new Error('nope')) })

    await waitFor(() => expect(screen.getByRole('alert').textContent).toContain('Could not load'))
  })
})
