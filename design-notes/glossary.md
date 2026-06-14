# Glossary

Canonical names for cross-cutting concepts, so the same thing isn't called three things in
three files. When the informal framing, the design docs, and the schema disagree, **the name
in this file wins in code and schema**; the others are UI copy at most.

Sourced from the ADRs they cite — when an ADR and this file disagree, the ADR is authoritative
and this file should be corrected.

## Auth & tenancy (ADR-005, ADR-020, ADR-021)

| Term | What it is | Table / id / URI | Status |
|---|---|---|---|
| **User** | A person who logs in (the operator). | `users` · UUIDv7 · `core://users/{uuid}` · `email` + `password_hash` | Current |
| **Tenant** | The ownership/isolation boundary every domain row hangs off via `tenant_id`. | `tenants` · UUIDv7 · `core://tenants/{uuid}` · `slug` + `name` | Current |
| **Membership** | The join row binding a User to a Tenant *with a role*. v0 has exactly one. | `memberships` (`user_id`, `tenant_id`, `role`); unique `(user_id, tenant_id)` | Current |
| **owner** | **Not an entity** — a *value* of `Membership.role` (`MembershipRole::OWNER`, the only role today). | column value `'owner'` | Current |

### Collapsed / aliased terms

ADR-021 deliberately collapsed three words into the single `Tenant` entity:

- **"account"** — informal framing word → **Tenant**. May appear in UI copy; never in schema/code.
- **"workspace"** — used in older design docs and the event-table reference column `workspace_id`
  → **Tenant**, column **`tenant_id`**. ADR-021 considered and rejected `workspace` as the v0
  noun; Phase 0.2 (ADR-022) records the `workspace_id → tenant_id` mapping.

So **account = tenant = workspace**, and the one true name is **Tenant** (column `tenant_id`,
URI `core://tenants/{uuid}`).

### Two different "role" axes — do not conflate

1. **Security role** — `ROLE_USER`. At the Symfony firewall layer; drives `getRoles()` /
   `access_control`. About *authentication/authorization*. v0 has exactly one.
2. **Membership role** — `owner`. On the `Membership` join; about the *user's relationship to a
   tenant* (ADR-005's "model permissions even if everything is owner"). v0 has exactly one,
   not yet enforced.

`owner` is not a login role; `ROLE_USER` is not a tenancy role. Different layers, modeled
separately on purpose.

### Mental model

> A **User** logs in (authenticated as `ROLE_USER`). A **Membership** says that user is `owner`
> of a **Tenant**. The **Tenant** is the boundary every future table carries a `tenant_id` for.
> "Account" and "workspace" are just other words for that Tenant.

### Not built yet

ADR-021 keeps a future split open: `tenant` could become an org/billing boundary that *contains*
one or more `workspace` containers (a `workspaces` table appears, membership points at workspace).
Until the roadmap forces it, it stays one word.

## Conversation store (ADR-004, ADR-022, `event-sourced-conversations.md`)

The same informal→canonical discipline applies to the conversation domain:

| Informal | Canonical | Notes |
|---|---|---|
| **conversation** | **thread** | A thread contains turns. |
| — | **turn** | One assistant exchange within a thread; contains messages. |
| — | **message** | A user or assistant message; contains parts. |
| — | **message_part** | A content fragment of a message (text in v0). |

"conversation" may stay in UI copy; code and schema say **thread / turn / message / message_part**.
