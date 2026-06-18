import { ComposerPrimitive, MessagePrimitive, ThreadPrimitive } from '@assistant-ui/react'

/**
 * Center active-thread area: the assistant-ui message viewport + composer.
 * Lifted verbatim from the old `App.tsx` so the chat surface is unchanged;
 * it reads from the host-owned `ExternalStoreRuntime` provided by the shell.
 * Chat-surface polish (markdown/code/copy/autoscroll) is D5, not D1.
 */
export function ThreadSurface() {
  return (
    <ThreadPrimitive.Root className="thread">
      <ThreadPrimitive.Viewport>
        <ThreadPrimitive.Messages
          components={{
            UserMessage: () => (
              <div className="message message-user">
                <span className="message-role">user</span>
                <MessagePrimitive.Parts />
              </div>
            ),
            AssistantMessage: () => (
              <div className="message message-assistant">
                <span className="message-role">assistant</span>
                <MessagePrimitive.Parts />
              </div>
            ),
          }}
        />
      </ThreadPrimitive.Viewport>

      <ComposerPrimitive.Root className="composer">
        <ComposerPrimitive.Input className="composer-input" placeholder="Say something…" />
        <ComposerPrimitive.Send className="composer-send">Send</ComposerPrimitive.Send>
      </ComposerPrimitive.Root>
    </ThreadPrimitive.Root>
  )
}
