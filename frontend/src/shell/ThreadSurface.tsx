import { useCallback, useEffect, useRef, useState } from 'react'
import {
  ActionBarPrimitive,
  ComposerPrimitive,
  MessagePrimitive,
  ThreadPrimitive,
} from '@assistant-ui/react'

import { MarkdownText } from './MarkdownText'
import { ThreadPlaceholder, ThreadStatusBanner } from './ThreadStatus'
import { shouldShowJumpToLatest } from '../store/scrollState'
import type { ThreadView } from '../store/threadView'

/**
 * Center active-thread area: the assistant-ui message viewport + composer.
 *
 * D5 polish over D1's verbatim lift:
 *  - message text renders as markdown + highlighted code via {@link MarkdownText}
 *    (the `Text` slot of `MessagePrimitive.Parts`);
 *  - each message carries a copy action (`ActionBarPrimitive.Copy`, raw text);
 *  - the viewport autoscrolls to the latest delta (assistant-ui's `Viewport`
 *    `autoScroll`, on by default) and surfaces a "jump to latest" button when
 *    the reader has scrolled up (see {@link JumpToLatest}).
 *
 * D6 honest status rendering: the shell hands a `view` (derived purely from
 * reducer state via {@link deriveThreadView}). Pre-hydration shows a loading
 * placeholder, a hydrated-but-empty thread an empty placeholder, and a thread
 * with messages renders them plus a terminal/transient banner — a FAILED turn
 * keeps its partial text with an honest error affordance, a CANCELLED turn
 * keeps its partial text marked "stopped" (never the failure banner). The
 * composer stays mounted throughout so a fresh/empty thread can be typed into.
 *
 * It reads message data from the host-owned `ExternalStoreRuntime` provided by
 * the shell; the `view` carries the reducer status the runtime doesn't expose.
 */
export function ThreadSurface({ view }: { view: ThreadView }) {
  const viewportRef = useRef<HTMLDivElement>(null)

  return (
    <ThreadPrimitive.Root className="thread">
      <div className="thread-viewport-wrap">
        <ThreadPrimitive.Viewport ref={viewportRef}>
          {view.status === 'ready' ? (
            <>
              <ThreadPrimitive.Messages
                components={{
                  UserMessage: () => (
                    <div className="message message-user">
                      <span className="message-role">user</span>
                      <MessagePrimitive.Parts components={{ Text: MarkdownText }} />
                      <MessageActions />
                    </div>
                  ),
                  AssistantMessage: () => (
                    <div className="message message-assistant">
                      <span className="message-role">assistant</span>
                      <MessagePrimitive.Parts components={{ Text: MarkdownText }} />
                      <MessageActions />
                    </div>
                  ),
                }}
              />
              <ThreadStatusBanner banner={view.banner} />
            </>
          ) : (
            <ThreadPlaceholder status={view.status} />
          )}
        </ThreadPrimitive.Viewport>
        <JumpToLatest viewportRef={viewportRef} />
      </div>

      <ComposerPrimitive.Root className="composer">
        <ComposerPrimitive.Input className="composer-input" placeholder="Say something…" />
        {/*
         * Stop ⇄ Send swap on the run lifecycle (D8, handoff §4 case 5). The
         * shell's `useExternalStoreRuntime` reports `isRunning` for both
         * 'streaming' and 'cancelling', so Stop stays visible through the
         * 'cancelling' transient and reverts to Send only once the terminal
         * `assistant_turn_cancelled` (or completed/failed) settles the run.
         * `Composer.Cancel` invokes the runtime `onCancel` wired in App.tsx
         * (`markCancelRequested` → POST …/cancel).
         */}
        <ThreadPrimitive.If running={false}>
          <ComposerPrimitive.Send className="composer-send">Send</ComposerPrimitive.Send>
        </ThreadPrimitive.If>
        <ThreadPrimitive.If running>
          <ComposerPrimitive.Cancel className="composer-cancel">Stop</ComposerPrimitive.Cancel>
        </ThreadPrimitive.If>
      </ComposerPrimitive.Root>
    </ThreadPrimitive.Root>
  )
}

/** Per-message action bar — a copy button that lifts the raw message text. */
function MessageActions() {
  return (
    <ActionBarPrimitive.Root className="message-actions">
      <ActionBarPrimitive.Copy className="copy-button message-copy" aria-label="Copy message">
        Copy
      </ActionBarPrimitive.Copy>
    </ActionBarPrimitive.Root>
  )
}

/**
 * "Jump to latest" affordance. Observes the assistant-ui viewport's scroll
 * position via a passive listener (non-invasive — it does not override the
 * Viewport's own `onScroll`, so native autoscroll keeps sticking to new
 * deltas), derives visibility through the pure {@link shouldShowJumpToLatest},
 * and scrolls back to the bottom on click. The real scroll/visual behavior is
 * flagged for Beau's live check; only the pure toggle is unit-tested.
 */
function JumpToLatest({ viewportRef }: { viewportRef: React.RefObject<HTMLDivElement | null> }) {
  const [visible, setVisible] = useState(false)

  useEffect(() => {
    const el = viewportRef.current
    if (!el) return
    const sync = () => setVisible(shouldShowJumpToLatest(el))
    sync()
    el.addEventListener('scroll', sync, { passive: true })
    return () => el.removeEventListener('scroll', sync)
  }, [viewportRef])

  const onClick = useCallback(() => {
    const el = viewportRef.current
    if (!el) return
    el.scrollTo({ top: el.scrollHeight, behavior: 'smooth' })
  }, [viewportRef])

  if (!visible) return null
  return (
    <button type="button" className="jump-to-latest" onClick={onClick}>
      ↓ Jump to latest
    </button>
  )
}
