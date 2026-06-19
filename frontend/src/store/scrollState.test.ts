import { describe, expect, it } from 'vitest'

import {
  BOTTOM_THRESHOLD_PX,
  distanceFromBottom,
  isAtBottom,
  shouldShowJumpToLatest,
} from './scrollState'

/**
 * Pure scroll-state toggle logic for the "jump to latest" affordance (D5).
 * The real DOM scroll/visual behavior is owned by assistant-ui's Viewport and
 * is flagged for Beau's live check — these cover only the derivation.
 */
describe('scrollState', () => {
  describe('distanceFromBottom', () => {
    it('is zero at the very bottom', () => {
      expect(distanceFromBottom({ scrollTop: 800, scrollHeight: 1000, clientHeight: 200 })).toBe(0)
    })

    it('is the gap above the bottom', () => {
      expect(distanceFromBottom({ scrollTop: 300, scrollHeight: 1000, clientHeight: 200 })).toBe(
        500,
      )
    })

    it('clamps overscroll to zero (never negative)', () => {
      expect(distanceFromBottom({ scrollTop: 900, scrollHeight: 1000, clientHeight: 200 })).toBe(0)
    })

    it('is zero when content is shorter than the viewport', () => {
      expect(distanceFromBottom({ scrollTop: 0, scrollHeight: 150, clientHeight: 200 })).toBe(0)
    })
  })

  describe('isAtBottom', () => {
    it('is true exactly at the bottom', () => {
      expect(isAtBottom({ scrollTop: 800, scrollHeight: 1000, clientHeight: 200 })).toBe(true)
    })

    it('is true within the default threshold', () => {
      const geom = { scrollTop: 800 - BOTTOM_THRESHOLD_PX, scrollHeight: 1000, clientHeight: 200 }
      expect(isAtBottom(geom)).toBe(true)
    })

    it('is false just beyond the threshold', () => {
      const geom = {
        scrollTop: 800 - BOTTOM_THRESHOLD_PX - 1,
        scrollHeight: 1000,
        clientHeight: 200,
      }
      expect(isAtBottom(geom)).toBe(false)
    })

    it('is true for non-scrollable (short) content', () => {
      expect(isAtBottom({ scrollTop: 0, scrollHeight: 150, clientHeight: 200 })).toBe(true)
    })

    it('honors a custom threshold', () => {
      const geom = { scrollTop: 700, scrollHeight: 1000, clientHeight: 200 } // 100 from bottom
      expect(isAtBottom(geom, 50)).toBe(false)
      expect(isAtBottom(geom, 150)).toBe(true)
    })
  })

  describe('shouldShowJumpToLatest', () => {
    it('is hidden at the bottom (inverse of isAtBottom)', () => {
      const geom = { scrollTop: 800, scrollHeight: 1000, clientHeight: 200 }
      expect(shouldShowJumpToLatest(geom)).toBe(false)
      expect(shouldShowJumpToLatest(geom)).toBe(!isAtBottom(geom))
    })

    it('is shown once scrolled up past the threshold', () => {
      const geom = { scrollTop: 200, scrollHeight: 1000, clientHeight: 200 }
      expect(shouldShowJumpToLatest(geom)).toBe(true)
      expect(shouldShowJumpToLatest(geom)).toBe(!isAtBottom(geom))
    })

    it('stays hidden for short, non-scrollable content', () => {
      expect(shouldShowJumpToLatest({ scrollTop: 0, scrollHeight: 150, clientHeight: 200 })).toBe(
        false,
      )
    })
  })
})
