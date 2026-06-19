import { SIGN_OUT_ACTION } from './routes'

interface TopBarProps {
  email: string | null
  tenantName: string | null
  bootstrapError: string | null
  /** Open the global default system-prompt editor (D10). Omit to hide it. */
  onOpenSettings?: () => void
}

/**
 * Persistent top bar: brand + a user menu showing the signed-in email and
 * tenant name (from `bootstrap`), plus a **Sign out** control. Sign-out is a
 * full-page POST to `/logout` (no JS, no CSRF token needed — logout CSRF is
 * not enabled on the firewall), so it works even if the SPA's JS is wedged.
 */
export function TopBar({ email, tenantName, bootstrapError, onOpenSettings }: TopBarProps) {
  return (
    <header className="app-topbar">
      <span className="app-brand">Proxenos</span>

      {bootstrapError && (
        <p role="alert" className="app-error">
          Bootstrap failed: {bootstrapError}
        </p>
      )}

      <div className="app-usermenu">
        {tenantName && <span className="app-tenant">{tenantName}</span>}
        {email && <span className="app-user-email">{email}</span>}
        {onOpenSettings && (
          <button type="button" className="app-settings" onClick={onOpenSettings}>
            Settings
          </button>
        )}
        <form method="post" action={SIGN_OUT_ACTION} className="app-signout">
          <button type="submit">Sign out</button>
        </form>
      </div>
    </header>
  )
}
