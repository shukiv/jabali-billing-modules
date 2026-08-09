# Jabali Panel — Blesta Module

Provisions Jabali Panel hosting accounts from Blesta via the Jabali
Automation API (HMAC-signed; no admin password stored in Blesta).

## Install

1. Copy this directory to your Blesta installation as
   `components/modules/jabali/` (the folder name must be exactly `jabali`).
2. In Jabali Panel, mint an automation token
   (**Admin → Automation → Mint Token**) with scopes:
   - `read:status`, `read:users`, `write:users` (baseline)
   - `delete:users` (needed for cancellation)
3. In Blesta: **Settings → Company → Modules → Available**, install
   *Jabali Panel*.
4. **Add Server**: panel hostname, port (8443), automation token ID +
   secret. Saving validates the connection live — a failure means wrong
   credentials, an unreachable panel, or clock skew (sync NTP).
5. Create a package: pick the Jabali package from the dropdown (needs a
   panel that exposes the package list) or paste the package ULID in the
   manual override field.

## Operation support

Suspend/unsuspend work against any current Jabali Panel. Create, cancel,
password change, package change and client SSO additionally require a
panel that advertises the matching capability (`users.create`,
`users.delete`, `users.password`, `users.package`, `users.login_token`) —
the module feature-detects and reports a clear error otherwise. See
`docs/API-CONTRACT.md` at the repository root.

## Requirements

Blesta 5.x, PHP 7.4+ with curl and json extensions.
