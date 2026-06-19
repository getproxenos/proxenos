// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest'
import { createElement } from 'react'
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import type { TextMessagePartProps } from '@assistant-ui/react'

import { MarkdownText, childrenToText } from './MarkdownText'

/**
 * D5 markdown rendering: assistant/user text renders as markdown with
 * syntax-highlighted fenced code (each with a copy button) and inline markup.
 */

// MarkdownText only reads `text`; the rest of TextMessagePartProps is irrelevant
// to rendering, so a minimal cast keeps the tests readable.
const renderMarkdown = (text: string) =>
  render(<MarkdownText {...({ text } as TextMessagePartProps)} />)

afterEach(() => {
  cleanup()
  vi.restoreAllMocks()
})

describe('childrenToText', () => {
  it('flattens nested elements (the highlighted span tree) back to raw text', () => {
    // Mirrors rehype-highlight output: raw strings interleaved with token spans.
    const tree = ['const ', createElement('span', { className: 'hljs-title' }, 'x', ' = ', '1')]
    expect(childrenToText(tree)).toBe('const x = 1')
  })

  it('ignores nullish and boolean nodes', () => {
    expect(childrenToText([null, undefined, false, 'ok'])).toBe('ok')
  })
})

describe('MarkdownText', () => {
  it('renders inline markdown (bold + inline code)', () => {
    const { container } = renderMarkdown('Hello **bold** and `inline`')
    expect(container.querySelector('strong')?.textContent).toBe('bold')
    // Inline code is a bare <code> (no <pre> ancestor).
    const inlineCode = container.querySelector('code')
    expect(inlineCode?.textContent).toBe('inline')
    expect(inlineCode?.closest('pre')).toBeNull()
  })

  it('renders a fenced code block with syntax highlighting', () => {
    const { container } = renderMarkdown('```js\nconst x = 1\n```')
    const pre = container.querySelector('pre.code-block-pre')
    expect(pre).not.toBeNull()
    // rehype-highlight tokenizes into hljs-* spans on the <code>.
    const code = pre!.querySelector('code')
    expect(code?.className).toContain('hljs')
    expect(container.querySelector('[class*="hljs-"]')).not.toBeNull()
  })

  it('gives each fenced code block a copy button', () => {
    renderMarkdown('```js\nconst x = 1\n```')
    expect(screen.getByRole('button', { name: 'Copy code' })).toBeTruthy()
  })

  it('copies the raw code (not the highlighted markup) on click', async () => {
    const writeText = vi.fn().mockResolvedValue(undefined)
    vi.stubGlobal('navigator', { clipboard: { writeText } })

    renderMarkdown('```js\nconst x = 1\n```')
    fireEvent.click(screen.getByRole('button', { name: 'Copy code' }))

    await waitFor(() => expect(writeText).toHaveBeenCalledWith('const x = 1'))
  })
})
