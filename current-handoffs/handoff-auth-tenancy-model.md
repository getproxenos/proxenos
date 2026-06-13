# Handoff — Auth + Tenancy Model (Phase 0.1)

Resolve the long-open "auth model" question for v0 and build the minimal identity layer:
account/tenant and user models, the membership relationship between them, and a console
command to mint them. No web registration, no self-service. This is the first real domain
on the skeleton, and it's the thing every later table hangs tenancy off.

## The open question this closes

The "auth model" open question (OIDC-from-the-start vs. password-first, "probably OIDC")
was never decided. Your stated approach — console-minted users, no registration UI —
actually *dissolves* it for v0: there is no self-service auth surface to argue about. v0
authenticates a console-created user; OIDC/SSO is a later, additive concern. Record that as
the decision rather than leaving the question open.

## Inputs to load

- ADR-005 (multi-tenancy from day one: tenant/workspace id on every relevant table;
  permissions modeled even if everything is `owner`).
- ADR-006 (personal-first; multi-user designed-for, not built).
- The identity URI shapes already in use: `core://tenants/personal`,
  `core://users/{uuid}` (operation-registry doc).
- ADR-007 (Doctrine), Symfony Security component.

## Terminology to settle first

- **Is "account" the same as "tenant"/"workspace", or layered?** The docs use
  tenant/workspace; the event table uses `workspace_id`. *Lean:* for v0 collapse
  account = tenant = workspace into one entity (call it `Tenant`, alias "account" in UI
  copy), but model it as its own table so a later split (tenant = org/billing boundary,
  workspace = container within) is a migration, not a redesign. Decide and write it down.

## Decisions to land

1. **Authentication mechanism for the web session.** Something must log a user in to the web
   app even with no registration. *Lean:* Symfony Security form login over a password hash,
   session-based. Simplest thing consistent with console-minted users. OIDC deferred.
2. **User ↔ tenant cardinality.** v0 is one user, one tenant. *Lean:* model a `membership`
   join (user, tenant, role) even though it's trivially one row with `role = owner` — this
   is ADR-005's "model permissions even if all owner" applied concretely. Avoids a painful
   later migration when a user belongs to several tenants.
3. **Identity format.** UUIDv7 (time-ordered, matching Hypomnema's choice), surfaced as
   `core://tenants/{uuid}` and `core://users/{uuid}` so the operation-registry's
   `request_context` shape is satisfied from day one.

## Console command

- `bin/console app:tenant:create` (or `app:account:create`) — mints a tenant.
- `bin/console app:user:create` — mints a user, hashes a password, attaches an owner
  membership to a named tenant.
- Idempotency / "already exists" handling is nice-to-have, not required.

## Hard exclusions

- No web registration, no password-reset flow, no email verification.
- No roles beyond `owner`; no permission *enforcement* logic yet (the column/role exists,
  checks are effectively always-true per ADR-005).
- No OIDC/SSO, no auth-as-extension (ADR-010 keeps that option open for later).

## Downstream

- 0.2 (conversation/message) hangs `workspace_id`/tenant scoping off this.
- Every future table inherits the tenancy column from the pattern set here.

## Definition of done

- [ ] Auth-for-v0 decision recorded (console-minted, password form login, OIDC deferred) —
      and the matching open-questions item marked resolved.
- [ ] `Tenant`, `User`, `Membership` Doctrine entities + migration, with UUIDv7 ids and the
      `core://` URI surface.
- [ ] Console commands create a tenant and a user; the user can log into a placeholder
      authenticated route.
- [ ] Account/tenant/workspace terminology decision written down.
