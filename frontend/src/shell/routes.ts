/**
 * Client-side route helpers for the SPA shell.
 *
 * The app is mounted under `/app/` (Vite `base` + the `SpaController`
 * catch-all). react-router's `basename="/app"` strips that prefix, so the
 * paths below are basename-relative — except `SIGN_OUT_ACTION`, which is a
 * full-page POST to the server-handled `/logout` route and therefore absolute.
 */

/** Server logout route (form_login on the `main` firewall, decision 8). */
export const SIGN_OUT_ACTION = '/logout'

/** Basename-relative path for a thread's client-side route. */
export const threadRoutePath = (threadId: string): string => `/threads/${threadId}`
