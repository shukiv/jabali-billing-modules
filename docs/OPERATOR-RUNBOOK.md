# Operator runbook

How to connect a billing system to a Jabali Panel host. Replace
`your-panel-host` with your panel's hostname throughout.

## 1. Mint an automation token

In the panel: **Admin → Automation → Mint Token**, or via the CLI:

```
jabali automation-token mint <name> --scope read:status --scope read:users \
  --scope write:users --scope delete:users
```

The **secret is shown once** — store it in the billing system's server
settings immediately. A write-capable token is admin-equivalent for the
accounts it can touch: prefer per-server tokens, set an expiry, restrict by
source IP where supported, and keep the kill-switch (revoke) handy.

### Scopes

| Scope | Grants |
|---|---|
| `read:status` | Connection test |
| `read:users` | List accounts, read usage, list packages |
| `write:users` | Create, suspend/unsuspend, change password, change package, mint SSO link |
| `delete:users` | Terminate (destructive; separate from `write:*` by design) |

Grant only what the modules you use need. Suspend/unsuspend + test work with
`read:status` + `write:users`.

## 2. Install the module

Copy the module folder into your billing system's module directory:

| System | Destination |
|---|---|
| WHMCS  | `modules/servers/jabali/` |
| Blesta | `components/modules/jabali/` |
| WiseCP | `coremio/modules/Servers/Jabali/` |

Set files owner-readable by the web user; keep the directory layout intact
(the module loads its bundled client from `lib/`).

## 3. Add the server / connection

In the billing admin, add a Jabali **server**:

| Field | Value |
|---|---|
| Hostname / Name | `your-panel-host` |
| Username | automation **token id** |
| Password | automation **token secret** |
| Port | panel port (default `8443`, always HTTPS) |

Then run the **connection test**. It should report the advertised
capabilities. "No write capabilities advertised" means the token lacks write
scopes or the panel is older than those endpoints.

## 4. Map products to packages

Each product maps to a Jabali **package id** (26-char ULID, from the panel's
Packages admin):

- WHMCS: set it in the product's module settings (config option).
- Blesta: set it on the package's module fields.
- WiseCP: set the `package_id` config option on the product.

The package id is applied at account creation and on upgrade/downgrade.

## 5. What each action does

Create → provisions the account (username + package). Suspend/unsuspend →
toggles account access. Change password / change package → applied directly.
Usage → disk/bandwidth for display. SSO → mints a single-use panel login link.
Terminate → deletes the account (requires `delete:users`).

Actions the connected panel does not advertise are disabled with a clear
message; the module keeps working for the actions it can perform.

## 6. Troubleshooting

| Symptom | Likely cause / fix |
|---|---|
| 401, "check NTP" | Client/panel clock skew > ~5 min. Sync NTP on both. |
| 401, "replay detected" | An identical signed request was retried too fast; the client re-signs on retry. Persistent = check for duplicate cron runs. |
| `429 rate_limited` | Per-token write rate limit hit; back off / stagger. |
| `scope_denied` | Token missing the scope for that action; re-mint with the scope. |
| "panel does not support X yet" | Panel is older than that endpoint; upgrade the panel. |
| SSO link opens to login | Panel version predates the one-click redemption; upgrade the panel. |

## 7. SSO behaviour

The SSO button mints a **single-use** panel login link (short TTL). On a
current panel, opening it logs the customer straight into their panel
dashboard. The redeeming client's IP is accepted but not bound (compensating
controls: single-use + short TTL + HTTPS).
