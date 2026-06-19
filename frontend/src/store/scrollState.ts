/**
 * Pure scroll-state derivation for the chat viewport's "jump to latest"
 * affordance (D5).
 *
 * The actual DOM scroll container is owned by assistant-ui's
 * `ThreadPrimitive.Viewport` (which also owns autoscroll-to-latest during
 * streaming). This module factors out the *decision* — "is the user parked at
 * the bottom, and therefore should the jump-to-latest button be hidden?" — into
 * a side-effect-free function so it can be unit-tested without a real browser.
 * The real scroll/visual behavior (smooth scroll, momentum, sticky-bottom while
 * deltas stream in) needs a live DOM and is flagged for Beau's manual check.
 */

/** The geometry an `onScroll` handler reads off the scroll container. */
export interface ScrollGeometry {
  /** Distance the content is scrolled from the top, in px. */
  scrollTop: number
  /** Full scrollable content height, in px. */
  scrollHeight: number
  /** Visible viewport height, in px. */
  clientHeight: number
}

/**
 * Slack (px) within which we still treat the viewport as "at the bottom".
 * A small tolerance absorbs sub-pixel rounding and the few px of jitter a
 * streaming delta introduces before the autoscroll settles, so the button
 * doesn't flicker on every token.
 */
export const BOTTOM_THRESHOLD_PX = 24

/** Pixels between the current scroll position and the very bottom. */
export function distanceFromBottom(geometry: ScrollGeometry): number {
  const { scrollTop, scrollHeight, clientHeight } = geometry
  return Math.max(0, scrollHeight - clientHeight - scrollTop)
}

/**
 * True when the viewport is at (or within `threshold` px of) the bottom.
 * Content shorter than the viewport is always "at bottom" — there is nothing
 * below to jump to.
 */
export function isAtBottom(
  geometry: ScrollGeometry,
  threshold: number = BOTTOM_THRESHOLD_PX,
): boolean {
  return distanceFromBottom(geometry) <= threshold
}

/**
 * Visibility of the "jump to latest" affordance: shown only once the user has
 * scrolled up away from the bottom. The inverse of {@link isAtBottom}; returning
 * to the bottom hides it (and the viewport resumes sticking to new deltas).
 */
export function shouldShowJumpToLatest(
  geometry: ScrollGeometry,
  threshold: number = BOTTOM_THRESHOLD_PX,
): boolean {
  return !isAtBottom(geometry, threshold)
}
