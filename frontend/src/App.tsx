import { useState } from 'react'
import { AssistantRuntimeProvider, useExternalStoreRuntime } from '@assistant-ui/react'

import { echoReply } from './echo'
import './index.css'

type Role = 'user' | 'assistant'

interface Message {
  id: string
  role: Role
  text: string
}

/**
 * Phase 0.0 frontend scaffold.
 *
 * Proves the toolchain (React 19 + assistant-ui on Vite 8) and the integration
 * path we grow in Phase 0.3: an `useExternalStoreRuntime` adapter over
 * host-owned conversation state. Here that "state" is local React state plus a
 * local echo; the real adapter binds to the event-sourced host store and streams
 * over Mercure. Rendering is intentionally plain React — the styled assistant-ui
 * Thread/primitives arrive with the real runtime.
 */
export function App() {
  const [messages, setMessages] = useState<Message[]>([])
  const [draft, setDraft] = useState('')

  const append = (text: string) => {
    const trimmed = text.trim()
    if (!trimmed) return
    setMessages((prev) => [
      ...prev,
      { id: crypto.randomUUID(), role: 'user', text: trimmed },
      { id: crypto.randomUUID(), role: 'assistant', text: echoReply(trimmed) },
    ])
  }

  const runtime = useExternalStoreRuntime({
    messages,
    convertMessage: (message: Message) => ({
      role: message.role,
      content: [{ type: 'text' as const, text: message.text }],
    }),
    onNew: async (message) => {
      const part = message.content[0]
      if (part?.type !== 'text') {
        throw new Error('Only text messages are supported in the 0.0 scaffold.')
      }
      append(part.text)
    },
  })

  return (
    <AssistantRuntimeProvider runtime={runtime}>
      <main className="app">
        <header className="app-header">
          <h1>bug-free-happiness</h1>
          <p>Phase 0.0 frontend scaffold — React 19 + assistant-ui on Vite 8.</p>
        </header>

        <section className="thread" aria-label="conversation">
          {messages.length === 0 ? (
            <p className="thread-empty">No messages yet. Say something below.</p>
          ) : (
            messages.map((message) => (
              <div key={message.id} className={`message message-${message.role}`}>
                <span className="message-role">{message.role}</span>
                <span className="message-text">{message.text}</span>
              </div>
            ))
          )}
        </section>

        <form
          className="composer"
          onSubmit={(event) => {
            event.preventDefault()
            append(draft)
            setDraft('')
          }}
        >
          <input
            className="composer-input"
            value={draft}
            onChange={(event) => setDraft(event.target.value)}
            placeholder="Say something…"
            aria-label="message"
          />
          <button className="composer-send" type="submit">
            Send
          </button>
        </form>
      </main>
    </AssistantRuntimeProvider>
  )
}
