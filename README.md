# Jabali Panel — Billing System Modules

Provisioning modules that let **WHMCS**, **Blesta**, and **WiseCP** manage
hosting accounts on **Jabali Panel** through its HMAC-signed Automation API —
create, suspend/unsuspend, change password, change package, read usage,
single sign-on, and terminate.

All three modules speak the same signed wire protocol through one shared PHP
client, so signing and error handling stay identical across platforms.

## Modules

| Billing system | Install location | Status |
|---|---|---|
| WHMCS  | `modules/servers/jabali/`          | Shares the verified client |
| Blesta | `components/modules/jabali/`        | Shares the verified client |
| WiseCP | `coremio/modules/Servers/Jabali/`   | Live-verified end-to-end (12/12 lifecycle ops) |

Each module feature-detects the panel's capabilities and degrades gracefully
when an action isn't available.

## Layout

```
shared/      Canonical HMAC API client + unit tests (signing vectors locked)
whmcs/       WHMCS server module (vendors the shared client)
blesta/      Blesta module   (vendors the shared client)
wisecp/      WiseCP ServerModule (vendors the shared client)
docs/        API contract, operator runbook
tools/       Live smoke harness
```

The shared client is the single source of truth; each module vendors a copy
under its own `lib/`, kept in lockstep by a drift check.

## Authentication

Requests use HMAC-SHA256 signing:

```
Authorization: Jabali-HMAC kid=<token-id>, ts=<unix>, sig=<hex>
sig = hex(HMAC_SHA256(secret, METHOD || "\n" || PATH || "\n" || ts || "\n" || hex(sha256(BODY))))
```

`PATH` includes the query string; retries re-sign with a fresh timestamp
(the panel replay-blocks reused signatures). Tokens are minted by the panel
operator and scoped (`read:*` / `write:users` / `delete:users`).

## Install (per module)

1. Copy the module folder into your billing system's module directory (see
   the table above).
2. In the billing admin, add a Jabali **Server**: hostname, automation token
   id (username), token secret (password), port.
3. Map each product to a Jabali **package** id.
4. Run the connection test.

## Development

- `shared/` holds the canonical client; run its unit tests before changing
  signing behavior.
- Keep each module's vendored `lib/` client identical to `shared/src/`.

## License

Proprietary — © Jabali. All rights reserved.
