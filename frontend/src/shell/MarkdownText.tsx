import type { ComponentPropsWithoutRef, ReactNode } from 'react'
import { isValidElement } from 'react'
import ReactMarkdown from 'react-markdown'
import remarkGfm from 'remark-gfm'
import rehypeHighlight from 'rehype-highlight'
import type { TextMessagePartProps } from '@assistant-ui/react'

import { CopyButton } from './CopyButton'

/**
 * Markdown renderer for assistant/user message text (D5).
 *
 * Wired in as the `Text` slot of `MessagePrimitive.Parts` so every text part —
 * streamed or replayed — renders as GitHub-flavored markdown. `remark-gfm`
 * adds tables/strikethrough/task-lists; `rehype-highlight` (highlight.js)
 * tokenizes fenced code into `hljs-*` spans, themed in `index.css`. Deps are
 * the same engine assistant-ui's own markdown primitive wraps, kept direct so
 * the per-code-block copy button (below) is ours to control.
 *
 * Fenced code blocks render through the {@link CodeBlock} `pre` override, which
 * adds a copy button; inline code falls through to the default `<code>` (styled
 * in CSS). react-markdown renders synchronously, so this is unit-testable in
 * jsdom without a live browser.
 */

/**
 * Recursively flatten React children back to their text, so a code block's
 * copy button copies the raw source rather than the highlighted markup
 * `rehype-highlight` injected as nested `<span>` elements.
 */
export function childrenToText(children: ReactNode): string {
  if (children == null || typeof children === 'boolean') return ''
  if (typeof children === 'string') return children
  if (typeof children === 'number') return String(children)
  if (Array.isArray(children)) return children.map(childrenToText).join('')
  if (isValidElement(children)) {
    return childrenToText((children.props as { children?: ReactNode }).children)
  }
  return ''
}

/** `pre` override: wraps a fenced code block with a per-block copy button. */
function CodeBlock({ children }: ComponentPropsWithoutRef<'pre'>) {
  // Strip the single trailing newline markdown leaves on fenced blocks.
  const raw = childrenToText(children).replace(/\n$/, '')
  return (
    <div className="code-block">
      <div className="code-block-toolbar">
        <CopyButton value={raw} label="Copy code" className="code-block-copy" />
      </div>
      <pre className="code-block-pre">{children}</pre>
    </div>
  )
}

const REMARK_PLUGINS = [remarkGfm]
const REHYPE_PLUGINS = [rehypeHighlight]

export function MarkdownText({ text }: TextMessagePartProps) {
  return (
    <div className="markdown">
      <ReactMarkdown
        remarkPlugins={REMARK_PLUGINS}
        rehypePlugins={REHYPE_PLUGINS}
        components={{ pre: CodeBlock }}
      >
        {text}
      </ReactMarkdown>
    </div>
  )
}
