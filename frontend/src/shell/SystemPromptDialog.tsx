import { useEffect, useState } from 'react'

interface SystemPromptDialogProps {
  /** Dialog heading, e.g. "Default system prompt" or "Thread system prompt". */
  title: string
  /** One-line helper text under the heading explaining the scope/precedence. */
  description: string
  /**
   * Load the current value when the dialog opens. The transport seam (mocked in
   * tests): the global-default editor resolves `GET /api/me/settings`; the
   * per-thread editor reads the focused thread's folded override (no GET
   * endpoint exists — see {@link ThreadState.systemPrompt}).
   */
  load: () => Promise<string | null>
  /**
   * Persist the value (the transport seam). Blank is normalized to `null` here
   * so a cleared editor honestly clears the prompt rather than storing "".
   */
  save: (value: string | null) => Promise<void>
  /** Close the dialog (Cancel, Escape, backdrop, or a successful save). */
  onClose: () => void
}

/**
 * Minimal system-prompt editor (D10). One reusable modal for both the global
 * default (off the TopBar user menu) and the per-thread override (off the active
 * thread) — decision 5, Hard exclusions forbid a broader settings page. It owns
 * only its own form state; loading and saving are injected so the component is
 * trivially testable against mocked transport.
 *
 * Lifecycle: on mount it `load()`s the current value into the textarea; Save
 * normalizes blank→null and `save()`s, closing on success; Cancel/Escape/
 * backdrop close without saving.
 */
export function SystemPromptDialog({
  title,
  description,
  load,
  save,
  onClose,
}: SystemPromptDialogProps) {
  const [value, setValue] = useState('')
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    let cancelled = false
    void (async () => {
      try {
        const current = await load()
        if (!cancelled) setValue(current ?? '')
      } catch {
        if (!cancelled) setError('Could not load the current value.')
      } finally {
        if (!cancelled) setLoading(false)
      }
    })()
    return () => {
      cancelled = true
    }
  }, [load])

  const commit = async (): Promise<void> => {
    setSaving(true)
    setError(null)
    // Blank clears the prompt; send null so the backend stores null, not "".
    const normalized = value.trim() === '' ? null : value
    try {
      await save(normalized)
      onClose()
    } catch {
      setError('Could not save. Please try again.')
      setSaving(false)
    }
  }

  return (
    <div
      className="app-modal-backdrop"
      onClick={onClose}
      onKeyDown={(event) => {
        if (event.key === 'Escape') onClose()
      }}
    >
      <div
        className="app-modal"
        role="dialog"
        aria-modal="true"
        aria-label={title}
        onClick={(event) => event.stopPropagation()}
      >
        <h2 className="app-modal-title">{title}</h2>
        <p className="app-modal-description">{description}</p>

        {error && (
          <p role="alert" className="app-error">
            {error}
          </p>
        )}

        <textarea
          className="app-modal-textarea"
          aria-label={title}
          rows={8}
          value={value}
          disabled={loading || saving}
          placeholder={loading ? 'Loading…' : 'Leave blank to clear.'}
          onChange={(event) => setValue(event.target.value)}
        />

        <div className="app-modal-actions">
          <button type="button" onClick={onClose} disabled={saving}>
            Cancel
          </button>
          <button type="button" onClick={() => void commit()} disabled={loading || saving}>
            {saving ? 'Saving…' : 'Save'}
          </button>
        </div>
      </div>
    </div>
  )
}
