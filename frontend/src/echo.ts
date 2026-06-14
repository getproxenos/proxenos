/**
 * Placeholder responder for the Phase 0.0 scaffold.
 *
 * The real assistant reply streams from the host over the Phase 0.3 streaming
 * contract; until then this local echo lets the external-store runtime wiring be
 * exercised end to end without a backend. Kept as a pure function so the toolchain
 * (Vitest) has something deterministic to test.
 */
export function echoReply(text: string): string {
  return `echo: ${text.trim()}`
}
