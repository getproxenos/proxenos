// @vitest-environment jsdom
import { afterEach, describe, expect, it } from 'vitest'
import { cleanup, render, screen } from '@testing-library/react'

import { TopBar } from './TopBar'

/**
 * Sign-out target (D1). The user menu shows email + tenant from bootstrap and
 * a Sign out control that POSTs to `/logout` — a full-page form so it works
 * without JS and without a CSRF token (logout CSRF is not enabled).
 */
describe('TopBar', () => {
  afterEach(cleanup)

  it('signs out via a POST form targeting /logout', () => {
    render(<TopBar email="user@example.com" tenantName="Personal" bootstrapError={null} />)

    const button = screen.getByRole('button', { name: 'Sign out' })
    const form = button.closest('form')
    expect(form).not.toBeNull()
    expect(form!.getAttribute('action')).toBe('/logout')
    expect(form!.getAttribute('method')).toBe('post')
  })

  it('renders the signed-in email and tenant name', () => {
    render(<TopBar email="user@example.com" tenantName="Personal" bootstrapError={null} />)

    expect(screen.getByText('user@example.com')).toBeTruthy()
    expect(screen.getByText('Personal')).toBeTruthy()
  })

  it('surfaces a bootstrap error when present', () => {
    render(<TopBar email={null} tenantName={null} bootstrapError="boom" />)

    const alert = screen.getByRole('alert')
    expect(alert.textContent).toContain('boom')
  })
})
