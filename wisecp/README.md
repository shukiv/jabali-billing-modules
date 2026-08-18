# Jabali Panel — WiseCP Module

Provisions Jabali Panel hosting accounts from WiseCP via the Jabali
Automation API (HMAC-signed; no admin password stored in WiseCP).

## Install

1. Copy this directory to your WiseCP installation as
   `coremio/modules/Servers/Jabali/` (folder name must be exactly
   `Jabali`; the main class file is `Jabali.php`).
2. In Jabali Panel, mint an automation token
   (**Admin → Automation → Mint Token**) with scopes:
   - `read:status`, `read:users`, `write:users` (baseline)
   - `delete:users` (needed for termination)
3. In WiseCP admin: **Services → Hosting Management → Servers → Add
   Server**:
   - Name: the panel hostname (e.g. `panel.example.com`)
   - Username: the automation token **ID**
   - Password: the automation token **secret**
   - Port: `8443`. If your host's outbound firewall blocks 8443 (e.g. CSF's
     default `TCP_OUT`), the panel admin can turn on **Server API Access →
     "Also serve the API on port 443"** in the panel and you set this to `443`
     instead. 8443 stays the recommended default.
   - Run **Test Connection**.
4. On each hosting product, open module settings and set
   **Jabali Package ID** to the package ULID from
   Jabali Panel (**Admin → Packages**).

## Operation support

Suspend/unsuspend and Test Connection work against any current Jabali
Panel. Create, terminate, password change, package change, client SSO
and disk/bandwidth usage additionally require a panel version that
advertises the matching capability (`users.create`, `users.delete`,
`users.password`, `users.package`, `users.login_token`, `usage.read`) —
the module feature-detects and reports a clear error otherwise. See
`docs/API-CONTRACT.md` at the repository root.

## Notes

- The "FTP details" WiseCP records at creation point at the panel's
  SFTP service (port 22) — Jabali has no plain FTP.
- Logs: WiseCP **Tools → Logs → Module Logs** (`Servers/Jabali`).
- Clock skew: the automation API allows ±5 minutes between WiseCP and
  the panel; keep both NTP-synced.

## Requirements

WiseCP 3.x (coremio), PHP 7.4+ with curl and json extensions.
