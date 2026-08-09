# Live smoke harness

Drives the shared `JabaliApiClient` against a **running** Jabali Panel — the
last mile that unit tests can't cover (real HMAC middleware, nginx→unix-socket
proxying, Redis replay gate, Kratos). House rule: build+vet+unit green ≠
working; verify on a box.

These scripts live-verify the modules against a Jabali Panel.

## Scripts

| Script | What it does | Scopes | Destructive? |
|---|---|---|---|
| `read_smoke.php` | status, capabilities, users, query-string signing, scope-denial, bad-secret 401; optional reversible disable/enable | `read:status read:users` (+`write:users` for the optional arg) | No (optional arg toggles one throwaway user) |
| `lifecycle_smoke.php` | full account lifecycle: create → exists-retry → 2nd-account → 409 → lookup → password → package → usage (per-user+bulk) → dry-run → delete | `read:* write:users delete:users` | Yes — throwaway user only, self-cleans |
| `sso_smoke.php` | mint login-token, assert URL is on-host + live, admin refused | `read:* write:users` (+`delete:users` for auto-cleanup) | Yes — throwaway user only |

## Run

```bash
# 1. Mint a scoped token on the panel (operator, on the box):
jabali automation-token mint smoke --scope 'read:*' --scope write:users --scope delete:users --json | tail -n +2

# 2. From this repo, point the script at the panel:
php tools/smoke/read_smoke.php      panel.example.com:8443 <token-id> <token-secret>
php tools/smoke/lifecycle_smoke.php panel.example.com:8443 <token-id> <token-secret>
php tools/smoke/sso_smoke.php       panel.example.com:8443 <token-id> <token-secret>

# 3. Revoke the token afterwards:
jabali automation-token revoke <token-id>
```

Each script prints `PASS/FAIL` per check and exits non-zero on any failure.

## Notes

- The panel must serve a browser-trusted cert on the given port (the client
  hard-verifies TLS — there is no insecure switch). Use the real hostname,
  not an IP whose cert won't match.
- `sso_smoke.php` verifies link **minting + liveness**, not end-user
  redemption. Redemption was browser-verified on 2026-08-08 and is **broken
  by design in v1**: the code-strategy recovery link carries no code and the
  SPA has no `/recovery` route, so opening it lands on `/login`
  unauthenticated (see `docs/HANDOFF.md` §6). A redemption harness
  (`sso_redeem.php`) exists for re-checking once the panel-side fix lands.
- Against a panel that predates the write endpoints, `lifecycle_smoke` and
  `sso_smoke` fail at the capabilities check by design — that panel only
  supports the read + suspend/enable surface. Use `read_smoke` there.
