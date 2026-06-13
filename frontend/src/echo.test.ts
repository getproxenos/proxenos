import { describe, expect, it } from 'vitest'

import { echoReply } from './echo'

describe('echoReply', () => {
  it('prefixes the input with "echo: "', () => {
    expect(echoReply('hello')).toBe('echo: hello')
  })

  it('trims surrounding whitespace', () => {
    expect(echoReply('  spaced  ')).toBe('echo: spaced')
  })
})
