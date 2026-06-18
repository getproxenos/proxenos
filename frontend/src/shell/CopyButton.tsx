import { useCallback, useRef, useState } from 'react'

/**
 * Copy-to-clipboard button with transient "Copied" feedback (D5).
 *
 * Used for per-code-block copy in {@link MarkdownText}. The per-*message* copy
 * action uses assistant-ui's `ActionBarPrimitive.Copy` instead, so it reads the
 * raw message text straight off the message runtime.
 *
 * `navigator.clipboard` is the modern path; a hidden-textarea `execCommand`
 * fallback keeps copy working on the non-secure-context / older-Safari paths a
 * real browser still hits. The actual clipboard write needs a real browser and
 * is flagged for Beau's live check — the pure feedback-toggle is exercised in
 * tests via a stubbed clipboard.
 */
export interface CopyButtonProps {
  /** Raw text written to the clipboard. */
  value: string
  /** Accessible label / tooltip; also the default (idle) button text. */
  label?: string
  /** ms the "Copied" state stays up before reverting. */
  copiedDuration?: number
  className?: string
}

export async function writeToClipboard(value: string): Promise<void> {
  if (navigator.clipboard?.writeText) {
    await navigator.clipboard.writeText(value)
    return
  }
  // Legacy fallback for non-secure contexts where the async API is absent.
  const textarea = document.createElement('textarea')
  textarea.value = value
  textarea.setAttribute('readonly', '')
  textarea.style.position = 'absolute'
  textarea.style.left = '-9999px'
  document.body.appendChild(textarea)
  textarea.select()
  document.execCommand('copy')
  document.body.removeChild(textarea)
}

export function CopyButton({
  value,
  label = 'Copy',
  copiedDuration = 2000,
  className,
}: CopyButtonProps) {
  const [copied, setCopied] = useState(false)
  const timeoutRef = useRef<ReturnType<typeof setTimeout> | null>(null)

  const onClick = useCallback(async () => {
    try {
      await writeToClipboard(value)
    } catch {
      // Clipboard denied/unavailable: leave the idle label, no feedback.
      return
    }
    setCopied(true)
    if (timeoutRef.current) clearTimeout(timeoutRef.current)
    timeoutRef.current = setTimeout(() => setCopied(false), copiedDuration)
  }, [value, copiedDuration])

  return (
    <button
      type="button"
      className={['copy-button', className].filter(Boolean).join(' ')}
      data-copied={copied || undefined}
      aria-label={label}
      title={label}
      onClick={onClick}
    >
      {copied ? 'Copied' : label}
    </button>
  )
}
