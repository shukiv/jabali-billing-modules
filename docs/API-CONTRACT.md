# Jabali Panel — Automation API contract

What the WHMCS / Blesta / WiseCP modules in this repository call on a Jabali
Panel host, and how the wire protocol works. This is the single source of
truth for the shared client (`shared/src/JabaliApiClient.php`).

All module traffic uses the **Automation API** with HMAC-signed requests — no
panel session, no cookies.

## 1. Authentication

```
Authorization: Jabali-HMAC kid=<token-id>, ts=<unix-seconds>, sig=<hex>

sig = hex(HMAC_SHA256(secret,
                       METHOD || "\n"
                    || PATH   || "\n"   // PATH includes the query string
                    || ts     || "\n"
                    || hex(sha256(BODY))))
```

Constraints the client library honors:

| Constraint | Consequence for the client |
|---|---|
| ~5-minute clock-skew window | A skew-related 401 is reported distinctly ("check NTP"). |
| Signature replay-block | A retry MUST re-sign with a fresh `ts` — never resend an identical signature. |
| Signed PATH includes the query string | Sign exactly the path+query bytes that go on the wire. |
| Handler parses the signed bytes | Send exactly the signed body; no re-serialization between sign and send. |
| Read envelope vs write envelope | Client exposes both (see §3). |
| Per-token write rate limit → `429 rate_limited` | Surfaced as a retry-able error class. |
| `GET /api/v1/automation/capabilities` advertises mounted actions | Modules feature-detect and degrade gracefully (§5). |

Tokens are minted by the panel operator and scoped (`read:*` / `write:users` /
`delete:users`). The secret is shown once at mint time.

## 2. Operation matrix

| Billing operation | WHMCS | Blesta | WiseCP | Endpoint | Scope |
|---|---|---|---|---|---|
| Test connection | `TestConnection` | row validation | `testConnect()` | `GET /automation/status` | `read:status` |
| List accounts | `ListAccounts` | — | — | `GET /automation/users` | `read:users` |
| Create account | `CreateAccount` | `addService` | `create()` | `POST /automation/users` | `write:users` |
| Suspend | `SuspendAccount` | `suspendService` | `suspend()` | `POST /automation/users/:id/disable` | `write:users` |
| Unsuspend | `UnsuspendAccount` | `unsuspendService` | `unsuspend()` | `POST /automation/users/:id/enable` | `write:users` |
| Terminate | `TerminateAccount` | `cancelService` | `terminate()` | `DELETE /automation/users/:id` | `delete:users` |
| Change password | `ChangePassword` | client action | `change_password()` | `POST /automation/users/:id/password` | `write:users` |
| Change package | `ChangePackage` | `changeServicePackage` | `apply_updowngrade()` | `PUT /automation/users/:id/package` | `write:users` |
| Panel SSO | `ServiceSingleSignOn` | client tab action | `use_clientArea_SingleSignOn()` | `POST /automation/users/:id/login-token` | `write:users` |
| Usage (disk/bw) | `UsageUpdate` | usage display | `getDisk()` / `getBandwidth()` | `GET /automation/users/:id/usage` | `read:users` |
| Package list | `ConfigOptions` | `getPackageFields` | product mapping | `GET /automation/packages` | `read:users` |

## 3. Envelopes & error taxonomy

- **Read** responses: `{ data, total }`.
- **Write** responses: `{ ok, status, message, operation_id?, user_id? }`.
- **Write errors**: `{ ok:false, error, message }` with `error` in
  `scope_denied | not_found | conflict | unsupported | rate_limited | internal`.

`JabaliApiException` carries the HTTP status, the `error` code, a `retryable`
flag (`rate_limited`, `5xx`, network, replay), and an `isSkew` flag (401 with
large local-vs-`Date`-header drift). Retries: max 2, exponential backoff,
**always re-signed** with a fresh `ts`; never for non-idempotent writes unless
the endpoint is idempotent-by-state (suspend/enable are; create is not). A
"replay detected" 401 on an idempotent request is retried once the unix second
rolls over.

## 4. Identity semantics

The panel's unique identity is the **username**. Email is deliberately **not**
unique — one billing client may own several hosting accounts on one email.
Same email + new username creates a second account; only a username collision
returns `409 conflict`. Create is idempotent keyed on the (supplied or
email-derived) username when the email also matches.

Consequences for the modules:

- Persist the returned **user id (26-char ULID)** as the primary account key.
- Username lookup is the fallback.
- An email-only lookup is treated as ambiguous unless it matches exactly one
  account (the shared client enforces this).

Identity mapping storage:

- WHMCS: `username` on the service + ULID in a module custom field.
- Blesta: service fields `jabali_user_id`, `jabali_username`, `jabali_domain`.
- WiseCP: `creation_info` array persisted on the order.

## 5. Feature detection & graceful degradation

At configure/test time each module calls `GET /capabilities` (plus
`GET /status` as the connection test). Actions whose capability is absent are
disabled with a clear message rather than failing mid-provision:

- WHMCS: lifecycle function returns an explanatory error string (logged via `logModuleCall`).
- Blesta: an `Input` error is set with the same message.
- WiseCP: `$this->error` is set and `false` returned.

Suspend/unsuspend and the connection test work against any current panel.
