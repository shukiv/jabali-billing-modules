# Jabali Panel — WHMCS Provisioning Module

Provisions Jabali Panel hosting accounts from WHMCS via the Jabali
Automation API (HMAC-signed; no admin password stored in WHMCS).

## Install

1. Copy this directory to your WHMCS installation as
   `modules/servers/jabali/` (the folder name must be exactly `jabali`).
2. In Jabali Panel, mint an automation token:
   **Admin → Automation → Mint Token** with scopes:
   - `read:status`, `read:users`, `write:users` (baseline)
   - `delete:users` (needed for Terminate)
   Copy the token **ID** and **secret** — the secret is shown only once.
3. In WHMCS: **System Settings → Servers → Add New Server**
   - Module: *Jabali Panel*
   - Hostname: panel hostname (e.g. `panel.example.com`)
   - Port: `8443` (default)
   - Username: the automation token **ID**
   - Password: the automation token **secret**
   - Click **Test Connection** — it reports the panel's advertised
     capabilities.
4. Create a product with Module = *Jabali Panel* and pick the Jabali
   package in Module Settings (or paste the package ULID in the override
   field).

### Recommended: `jabali_user_id` custom field

Add a product **custom field** named `jabali_user_id` (admin-only,
text). The module stores the Jabali user ULID there at CreateAccount and
uses it for all later operations. Without it, the module falls back to
matching by username/email against the panel's user list.

## Operation support

Suspend/Unsuspend and Test Connection work against any current Jabali
Panel. Create, Terminate, Change Password, Change Package, SSO and usage
sync additionally require a panel version that advertises the matching
capability (`users.create`, `users.delete`, `users.password`,
`users.package`, `users.login_token`, `usage.read`) — the module
feature-detects and returns a clear error otherwise. See
`docs/API-CONTRACT.md` at the repository root.

## Troubleshooting

- **Clock skew error** — the automation API allows ±5 minutes between
  WHMCS and the panel. Sync both hosts with NTP.
- **`scope_denied`** — re-mint the token with the scopes listed above.
- **TLS errors** — the panel must serve a browser-trusted certificate on
  `:8443` (Jabali issues one via Let's Encrypt; see panel docs). The
  module intentionally has no "ignore certificate" switch.
- Module log: **Utilities → Logs → Module Log** (secrets are scrubbed).

## Requirements

WHMCS 8.x, PHP 7.4+ with curl and json extensions.
