---
title: "REST API reference"
description: "Carbure REST API reference: JWT authentication, transactions, accounts, bank synchronization, budgets, categories, rules and administration."
icon: material/api
---

# API reference

- **Base**: `https://<server>/api`
- **Format**: JSON in (body) and out. Parameters can also be passed in the query
  string; body and query string are merged.
- **Authentication**: `Authorization: Bearer <token>` header (JWT obtained from
  `POST /user/login`) on every route, except the **public** routes:
  `POST /user/login`, `GET /bank/sync` (JWT or `sync_token`), `POST /mcp` and `GET /mcp`
  (access token or JWT), `GET /health`, and the OAuth routes for AI agents (metadata
  `/.well-known/oauth-*`, `POST /oauth/register`, `GET /oauth/authorize`,
  `POST /oauth/token`). A unit test checks this list.
- **Named parameters**: each request key maps to a parameter of the PHP method. An
  unknown key returns a 400 error.
- **Profiles**: a **user** views and checks off transactions, budgets, insights and
  trends. An **administrator** also manages rules, categories, accounts, insights, the
  MCP server, users, the configuration and the logs: routes marked
  "administrator" return `403` to everyone else. They are declared in their attribute
  (`#[ApiRoute(..., admin: true)]`) and checked before the method is called; a unit test
  pins the access level (public, user, administrator) of every route, and Swagger shows it
  (`x-carbure-access` extension).

## Errors { #erreurs }

| Code | Case |
|---|---|
| 400 | Missing, unknown or invalid parameter; invalid JSON |
| 401 | Missing, invalid or expired JWT; invalid credentials; invalid MCP access token |
| 403 | Action restricted to administrators; MCP server disabled; origin rejected; wrong setup code |
| 404 | Route or resource not found |
| 405 | `GET /mcp` (the MCP server does not open a stream) |
| 409 | Conflict (duplicate, deleting a user who owns transactions, last administrator…) |
| 423 | Account locked after too many failed logins |
| 500 | Server error (database, woob, migration…) |
| 503 | Carbure is not installed yet (see [Setup wizard](#assistant-dinstallation)) |

```json
{ "error": "Unauthorized - Invalid or missing JWT token", "code": 401, "uid": "6ac106d3912d9" }
```

`uid` identifies the request in `data/logs/carbure_YYYYMMDD.log`.

## Users { #utilisateurs }

### `POST /user/login` — public { #post-userlogin-publique }

| Parameter | Type | Required | Description |
|---|---|---|---|
| `username` | string | yes | Username |
| `password` | string | yes | Password |
| `device` | object `{name, token, platform?, model?, os_version?, app_version?}` | no | Mobile device to register for notifications (see [the `device` object](#objet-device)) |
| `mfa` | boolean | no | The client supports two-factor authentication (`true` for the portal) |

```bash
curl -X POST https://exemple.fr/api/user/login \
  -H 'Content-Type: application/json' \
  -d '{"username":"alice","password":"…","device":{"name":"iPhone16,1","token":"<token APNs>","platform":"ios","model":"iPhone16,1","os_version":"iOS 26.0","app_version":"1.2"}}'
# Android: "device":{"name":"Google Pixel 8","token":"<token FCM>","platform":"android","model":"Google Pixel 8","os_version":"Android 15","app_version":"1.0"}
```

```json
{ "exp": 1762000000, "sub": 1, "iat": 1759400000, "token": "eyJ0eXAiOiJKV1Qi…" }
```

The token is valid for 30 days. The login date is recorded (`last_login`).

`401` if the credentials are wrong; `423` if the account is locked: after 5 failures in a
row, the account is locked for 24 hours, even with the right password (the 5th failure
already answers `423`). A successful
login resets the counter.

**Two-factor authentication** (see [Security](securite.md#double-authentification-totp)):
when it is enforced (`mfa_required`) or enabled by the user, and neither the device
(`device.token` already registered for this user) nor the browser (trust cookie) is known,
the response is not the session but a challenge valid for 5 minutes:

```json
{ "mfa": "verify", "mfa_token": "eyJzdWIiOjIs…", "exp": 1759400300 }
```

`mfa` is `enroll` when the user has no authenticator app yet: the response adds
`secret` (base32) and `otpauth` (QR code URI). A client that does not send `mfa=true`
gets `403` (explicit message) instead of the challenge; the response format does not change
for a user without two-factor authentication. A new device is only registered
after the code.

#### `device` object { #objet-device }

Sent by the mobile apps (the portal does not send one); the device is created or
updated (user, name, platform, model and versions) at each login.

| Field | Type | Required | Description |
|---|---|---|---|
| `name` | string | no | Device name |
| `token` | string | yes | Push token (APNs for iOS, FCM for Android); without a token, no device is registered |
| `platform` | string | no | `ios` or `android` (case and surrounding spaces ignored; `400` for any other value). Missing or empty: inferred from the token — 64 hexadecimal characters (APNs) = `ios`, any other token (FCM: long, with `:`, `-`, `_`) = `android` |
| `model` | string | no | Model (`iPhone16,1`, `Google Pixel 8`), 100 characters at most |
| `os_version` | string | no | System version (`iOS 26.0`, `Android 15`), 50 characters at most |
| `app_version` | string | no | App version (`1.2`), 20 characters at most |

Texts are trimmed and truncated to their maximum length; a missing or empty field is
stored as `null`. Until the
`2026-10-25_device_model` migration is applied, `model`, `os_version` and `app_version` are
ignored (the login does not fail).

iOS (the APNs token is 64 hexadecimal characters):

```json
{ "name": "iPhone16,1", "token": "a1b2c3…", "platform": "ios",
  "model": "iPhone16,1", "os_version": "iOS 26.0", "app_version": "1.2" }
```

Android (FCM token):

```json
{ "name": "Google Pixel 8", "token": "fXk9…:APA91b…", "platform": "android",
  "model": "Google Pixel 8", "os_version": "Android 15", "app_version": "1.0" }
```

### `POST /user/login/mfa` — public { #post-userloginmfa-publique }

Second step of the login: exchanges the challenge and the code for the session.

| Parameter | Type | Required | Description |
|---|---|---|---|
| `mfa_token` | string | yes | Challenge returned by `POST /user/login` |
| `code` | string | yes | 6-digit code from the authenticator app, or recovery code (`xxxxx-xxxxx`) |
| `trust` | boolean | no | Trust this browser for 6 months (`HttpOnly` cookie) |
| `device` | object `{name, token, platform?, model?, os_version?, app_version?}` | no | Mobile device to register, as for `POST /user/login` (see [the `device` object](#objet-device)) |

Response: the session, as for `POST /user/login` (`exp`, `sub`, `iat`, `token`), plus
`recovery_codes` (10 recovery codes, shown only once) when the authenticator app has just
been set up. `401` if the code is wrong (counted toward the lockout), `423` if the account
is locked, `403` if the challenge has expired.

### `GET /user/mfa`, `POST /user/mfa/setup`, `POST /user/mfa/enable`, `POST /user/mfa/recovery-codes`, `DELETE /user/mfa` { #get-usermfa-post-usermfasetup-post-usermfaenable-post-usermfarecovery-codes-delete-usermfa }

Two-factor authentication of the logged-in user (My profile):

- `GET /user/mfa`: `available` (migration applied), `required` (enforced by the
  administrator), `enabled`, `pending`, `recovery_codes_left`, `trusted_browsers`
  (`id`, `label` = User-Agent, `created_at`, `expires_at`, `last_used_at`);
- `POST /user/mfa/setup`: new secret to scan (`secret`, `otpauth`), `409` if already
  enabled;
- `POST /user/mfa/enable` (`code`): confirms the authenticator app, returns `recovery_codes`;
- `POST /user/mfa/recovery-codes` (`code`): new recovery codes;
- `DELETE /user/mfa` (authenticator or recovery `code`, in the body): disables
  two-factor authentication and revokes the trusted browsers.

A wrong code answers `400` (and counts as a failed login).

### `DELETE /user/mfa/browser?id=` { #delete-usermfabrowserid }

Revokes a trusted browser of the logged-in user (`404` otherwise).

### `POST /user/mfa/reset` — administrator { #post-usermfareset-administrateur }

`id`: removes the authenticator app, the recovery codes and the trusted browsers of a
user who lost their phone; they will set up a new authenticator app at their
next login if two-factor authentication is enforced.

### `POST /user/unlock` — administrator { #post-userunlock-administrateur }

`id`: unlocks an account locked after too many failed logins (`404` if the user
does not exist).

### `GET /user/me` { #get-userme }

Profile of the logged-in user: `id`, `username`, `firstname`, `lastname`, `email`,
`is_admin`, `language`, `theme` (portal theme: `system`, `light` or `dark`),
`alert_threshold` (`null` if alerts are disabled).

```bash
curl https://exemple.fr/api/user/me -H "Authorization: Bearer $TOKEN"
```

### `GET /user` { #get-user }

List of users for an administrator; the user themselves otherwise. Same fields
as `GET /user/me`, plus `last_login` (last successful login, `null` if never) and
`locked_until` (end of the lockout, `null` if the account is not locked).

### `POST /user` — administrator { #post-user-administrateur }

`username`, `password`, `email` (required), `firstname`, `lastname`, `is_admin` (optional;
user profile by default). `400` if the username or the email already exists.

### `PUT /user` { #put-user }

| Parameter | Required | Description |
|---|---|---|
| `id` | yes | User to update (yourself, or anyone for an administrator) |
| `email`, `firstname`, `lastname` | no | New values |
| `password` | no | New password (ignored if empty) |
| `language` | no | `fr`, `en`, `es`, `it`, `de` or `pt` (European Portuguese); `400` for any other value |
| `theme` | no | Portal theme: `system` (follows the system's light/dark preference, default), `light` or `dark`; `400` for any other value |
| `alertThreshold` | no | Alert threshold in euros for new expenses; `""` or `0` disables alerts |
| `is_admin` | no | Administrator (`true`) or user (`false`) profile; administrators only, `409` when removing the role from the last administrator |

```bash
curl -X PUT "https://exemple.fr/api/user?id=1" -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' -d '{"language":"en"}'
```

Returns the updated user.

### `GET /user/notifications` { #get-usernotifications }

Channels of each notification type for the logged-in user:

```json
{
  "preferences": {
    "sync": {"push": true, "email": false},
    "rule": {"push": true, "email": false},
    "expense": {"push": true, "email": true},
    "budget": {"push": false, "email": false}
  },
  "smtp": true,
  "address": "alice@example.com",
  "email_available": true,
  "push_available": true,
  "budget_threshold": 100,
  "alert_threshold": 200.0,
  "budget_category": null,
  "budgets": [{"id": 1, "name": "Alimentation"}, {"id": 2, "name": "Alimentation › Supermarché"}]
}
```

Types: `sync` (result of a synchronization), `rule` (new transaction matching a `notify`
rule), `expense` (expense above the alert threshold), `budget` (the month's spending in a
budgeted category above the budget threshold). Without a choice from the user, a type is
sent by push and not by email, except `budget`, disabled on every channel (to be enabled).
`smtp` tells whether an SMTP server is configured, `address` the profile's address (`null`
if none), `email_available` whether emails can be sent (both), `push_available`
whether an API key for the push notification service is configured (otherwise no
push notification is sent) and `budget_threshold`
the budget alert threshold, as a percentage of the budget (100 by default). `alert_threshold`
is the amount of a large expense (`expense` type, `null`: disabled, the same as in
`GET /user/me`), `budget_category` the category whose budget is watched by the budget
alerts (`null`: all budgets) and `budgets` the expense categories that have a
budget this month (a subcategory named "Category › Subcategory"), plus the watched
one if it has none. Notifications (push and email) are
written in the profile's language (`language`, `fr` by default).

### `PUT /user/notifications` { #put-usernotifications }

| Parameter | Required | Description |
|---|---|---|
| `preferences` | no | `{type: {push?, email?}}`, each channel `true`/`false` (or `"true"`, `"false"`, `1`, `0`) |
| `budgetThreshold` | no | Budget alert threshold (`budget` type) as a percentage of the budget: integer from 50 to 200 (number or string) |
| `alertThreshold` | no | Amount of a large expense (`expense` type) in euros; `""` or `0` disables the alert |
| `budgetCategory` | no | Expense category whose budget is watched (`budget` type); `0` or `""`: all budgets |

Missing types, channels and settings are kept; an invalid type, channel, value or setting
(including an unknown or income category): `400`, nothing saved;
`budgetCategory` before the `2026-10-23_user_budget_alert_category` migration: `409`. Returns the result of `GET /user/notifications`.

```bash
curl -X PUT "https://exemple.fr/api/user/notifications" -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' -d '{"preferences":{"expense":{"email":true}}}'

# Budget alerts by email, from 90% of the budget
curl -X PUT "https://exemple.fr/api/user/notifications" -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' -d '{"preferences":{"budget":{"email":true}},"budgetThreshold":"90"}'

# Only the budget of category 4, and expenses from 150 €
curl -X PUT "https://exemple.fr/api/user/notifications" -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' -d '{"budgetCategory":"4","alertThreshold":"150"}'
```

### `DELETE /user` — administrator { #delete-user-administrateur }

`id`: you cannot delete yourself (400) or delete a user who owns
transactions (409).

## Accounts and synchronization { #comptes-et-synchronisation }

Bank accounts belong to the **household**: every user sees every account and its
transactions. An account's `user_id` only indicates the user who
added it.

### `GET /bank` { #get-bank }

Bank accounts of the household, visible to every user (an account appears only
once); `label` is the name given to the account (`null` if it has none):

```json
[{ "id": 1, "bankId": "DEMO0000001@bnp", "account_number": "DEMO0000001", "bank_name": "bnp", "label": "Compte joint" }]
```

### `GET /bank/accounts` — administrator { #get-bankaccounts-administrateur }

Every account of the household with the user who added it and the result of its
last synchronization: `id`, `bankId`, `account_number`, `bank_name`, `label`, `user_id` and
`username` (who added it), `last_sync_at`, `last_sync_status` (`OK`, `ERROR` or `null`),
`last_sync_message` (number of new transactions or error).

### `POST /bank` — administrator { #post-bank-administrateur }

Follows an account for the household. Parameters: `account_number` (account identifier in
woob), `bank_name` (woob backend name) and `label` (optional: name given to the account,
100 characters maximum); the account's `user_id` is the administrator who adds it. `409` if the household already follows this account, `400` if an identifier is invalid.

### `PUT /bank` — administrator { #put-bank-administrateur }

Updates a followed account: `id`, `account_number`, `bank_name` and `label` (optional:
missing, the current name is kept; empty, it is removed). `404` if unknown, `409` if the
household already follows the target account.

### `DELETE /bank?id=` — administrator { #delete-bankid-administrateur }

Stops following the account; its already imported transactions are kept.

### `GET /bank/backends`, `GET /bank/modules` — administrator { #get-bankbackends-get-bankmodules-administrateur }

Banks configured in woob (`name`, `module`, `label`: name given to the connection, `null`
if none) and banks supported by woob (`module`, `description`). A bank must be configured in woob (bank credentials) before
its accounts can be synchronized: from the portal (routes below).

### `GET /bank/module?module=` — administrator { #get-bankmodulemodule-administrateur }

Parameters requested by a woob module: `module`, `description`, `fields` (`key`, `label`,
`description`, `default`, `required`, `masked`, `choices`). Nothing secret is returned.
A module not installed yet is installed first; `409` if the woob repository is not yet
approved (installing it would trust its key, see `GET /bank/woob/update`), as
for `POST /bank/backend`.

### `POST /bank/backend` — administrator { #post-bankbackend-administrateur }

Configures a bank in woob: `module`, `backend` (name, `[a-z0-9_-]`, no leading
`-`), `settings` (object of the module's parameters; only its keys are passed on,
values on a single line with no spaces, choices checked), `label` (optional: name given to
the connection, 100 characters max, for example "Joint account"). The credentials are
handed to woob, neither stored nor logged by Carbure (`settings` is masked in the
logs), and never passed on the woob command line. Returns
`backend`, `label` and the `accounts` found (same format as `/bank/discover`). `409` if
a bank with this name already exists in woob or if its module is not installed and the woob
repository not yet approved, `400` if a parameter is missing or if woob refuses.

### `PUT /bank/backend` — administrator { #put-bankbackend-administrateur }

Renames a bank connection: `backend` (name in woob), `label` (name given to the
connection; empty removes it). The name in woob, which identifies the accounts and the
transactions, does not change. Returns `name` and `label`. `404` if the bank is not
configured in woob, `400` if the name is invalid or too long.

### `DELETE /bank/backend` — administrator { #delete-bankbackend-administrateur }

Deletes a bank connection: `backend` (name in woob, in the query string). woob
forgets it along with its credentials (`woob config remove`), its given name and its followed
accounts are deleted; already imported transactions are kept. Returns `true`. `404` if
the bank is not configured in woob, `400` if the name is invalid, `500` if woob did not
delete it.

### `GET /bank/woob` — administrator { #get-bankwoob-administrateur }

On a fresh installation (woob never run), every route that runs woob
answers `409` until the key of its repository is approved (`GET /bank/woob/update`):
on its first run, woob would trust that key on its own.

State of the woob bank module repository: `repository` (URL), `trusted` (key already
approved), `fingerprint` (SHA-1 fingerprint of the approved key, otherwise of the one woob
will ask to approve, to compare with the one published by the woob maintainers; `null` if
the repository is unreachable) and `error` (cause, `null` otherwise).

### `GET /bank/woob/update` — administrator (SSE stream) { #get-bankwoobupdate-administrateur-flux-sse }

Updates the repositories and the bank modules (`woob config update`). Optional
parameter `fingerprint` (SHA-1 fingerprint confirmed by the administrator, `400` if it is
not 40 hexadecimal characters). As long as the repository key is not approved,
woob (which would trust it on its own) is only run if `fingerprint` is
the fingerprint of the key that Carbure downloads just before, and the key kept by woob
must be that one (otherwise it is deleted). Each woob line is sent
(`data: <line>`), then the result: `Woob updated`, `Woob repository not trusted:
<fingerprint>` (woob not run: compare it before trying again with it) or `Woob update
failed: <cause>` (including `synchronization in progress`: never during a
synchronization).

### `GET /bank/discover` — administrator { #get-bankdiscover-administrateur }

Queries woob (`woob bank list`) and returns the accounts of the configured banks:
`bankId`, `account_number`, `bank_name`, `label`, `balance`, `currency`, `followed`. May
take several seconds (connection to the banks).

| Parameter | Description |
|---|---|
| `backend` (optional) | Only the accounts of this connection (woob backend, `woob bank list -b <backend>`); `400` if the name is invalid |

### `GET /bank/sync` — SSE stream { #bank-sync }

Starts the synchronization of **all** accounts, or of a single one with
`?account=<account_number>@<bank_name>` (`404` if it is not followed). The date and the result
are recorded for each account. Access is allowed with:

- a valid JWT (any user), **or**
- the `sync_token` from the configuration, in the `X-Sync-Token` header.

!!! warning "Deprecated `?token=` parameter"
    The `sync_token` passed in the URL (`?token=`) is still accepted for scheduled tasks
    already configured, but it is **deprecated**: the URL is written to the proxy logs, the
    nginx logs and the shell history. The response then carries the headers
    `Deprecation: @1792108800` (deprecated since October 16, 2026, [RFC 9745](https://www.rfc-editor.org/rfc/rfc9745))
    and `Link: <https://carbure.org/api/#bank-sync>; rel="deprecation"`, and a warning
    (without the token) is logged. Replace it with the `X-Sync-Token` header; if both
    are present, the header wins.


Without a valid JWT or token (header or URL): `401`. `?interactive=true` (with `account`, for a logged-in user only,
`400` otherwise) allows woob to trigger strong authentication: woob runs on a
pseudo-terminal (PHP's `proc_open`) and its messages are relayed as
`data: Bank message <account>: <message>` while it waits for approval in the
bank's app. Without this parameter, a bank that requires strong
authentication fails with `…interactive mode to perform a two-factor authentication`.

Upcoming transactions are optional: if their woob call fails (other than
strong authentication), the history is still synchronized, the failure is sent as
`data: Coming failed <account>: <error>` and the account stays `OK`, with the result
`<n> new (coming failed: <error>)`.

For the scheduler (`sync_token`, never in interactive mode), an account whose bank
asks for approval in its app is synchronized again after `woob_2fa_wait`
seconds (15 by default), up to `woob_2fa_retries` times (1 by default): the approval may
have been given in the meantime. Only that account waits (`data: Waiting for a validation <account>:
new attempt in 15 s (1/1)`, then `: heartbeat` comments); only the result of the last
attempt is recorded and notified.

The response is a `text/event-stream`: `data: …` messages (progress,
result of each step) and `: heartbeat` comments every 15 s while
woob runs. Only one synchronization can run at a time (otherwise:
`data: Synchronization already in progress`).

```bash
curl -N -H "X-Sync-Token: $SYNC_TOKEN" https://exemple.fr/api/bank/sync
```

```text
data: Syncing DEMO0000001@bnp (coming)...
data: Done DEMO0000001@bnp (coming): 3 received, 1 new
data: Syncing DEMO0000001@bnp (history)...
data: Done DEMO0000001@bnp (history): 100 received, 4 new
data: Notifying users of DEMO0000001@bnp...
data: Updating missing categories...
data: Checking the budgets...
data: Synchronization complete
```

After each account, **every user** of the household receives a notification (success
or failure, number of transactions to check), each **new** expense (a transaction
not seen before) whose amount reaches a user's `alert_threshold` sends them a
"Large expense to check" notification, and each new transaction
matching a `notify` rule is reported to the household. Once the transactions are categorized, the
**budgets of the current month** are checked (`budget` alert, also after an import). Each
notification is sent in its recipient's language, on the channels (push, email) they
chose (see [`GET /user/notifications`](#get-usernotifications)).

## Transactions { #transactions }

### `GET /transaction` { #get-transaction }

| Parameter | Required | Description |
|---|---|---|
| `month`, `year` | no | Month of the transactions (current month by default) |
| `category` | no | Limits to the transactions of this category **and its subcategories** |

Transactions are sorted by actual date, newest first. Fields: `id`, `uuid`,
`imported`, `date`, `rdate`, `type`, `label`, `category`, `amount`, `card`, `bank_name`
and `account_number` (source account; `null` for transactions older than when these fields
were added), `pointed`, `user`.

```bash
curl "https://exemple.fr/api/transaction?month=9&year=2026&category=1" -H "Authorization: Bearer $TOKEN"
```

Types: 1 transfer, 2 direct debit, 3 check, 4 check deposit, 5 refund,
6 ATM withdrawal, 7 card statement, 8 expense, 9 fees, 12 pending card payment.

### `GET /transaction/periods` { #get-transactionperiods }

Months that have transactions, newest to oldest: `[{"year": 2026, "month": 10}, …]`.
The portal builds its month and year lists from it.

### `GET /transaction/recurring` { #get-transactionrecurring }

Recurring transactions of a month: found about once a month around the same day
(salary, rent, subscriptions, bills). A series groups the transactions in the same direction
(debit or credit) whose label, with digits removed (references, dates), is the same. It
is recurring if it appears in at least 3 months of the period, at most 1.5 times per month,
3 times out of 4 within 5 days of the same day, and is still active (seen in the month or the
previous month). A card payment must also keep the same amount within 20%
(a subscription, not the restaurant); a direct debit or a transfer may vary.

| Parameter | Required | Description |
|---|---|---|
| `month`, `year` | no | Month (current month by default) |
| `months` | no | Number of previous months examined (2 to 24, default 6) |

Response, sorted by day: `[{"label", "category", "amount" (last amount), "average",
"variable" (amount changing by more than 10%), "day" (usual day), "months" (number of
months it appears in), "last" (date of the last one), "ids" (its transactions in the month),
"status"}]`. `status` is `received`, or `expected` if it is not in the month yet.

```bash
curl "https://exemple.fr/api/transaction/recurring?month=10&year=2026" -H "Authorization: Bearer $TOKEN"
```

### `GET /transaction/search` { #get-transactionsearch }

| Parameter | Required | Description |
|---|---|---|
| `query` | yes | Text searched in the label (2 characters minimum, case-insensitive) |
| `limit` | no | Maximum number of results (1 to 500, default 100) |

```bash
curl "https://exemple.fr/api/transaction/search?query=amazon" -H "Authorization: Bearer $TOKEN"
```

### `POST /transaction/import/preview` { #post-transactionimportpreview }

Reads a bank statement without saving anything.

| Parameter | Required | Description |
|---|---|---|
| `file` | yes | File content encoded in base64 (1 MB at most before encoding); never written to the logs |
| `filename` | no | File name (its extension helps detect the format) |

Formats: OFX/QFX (1.x SGML and 2.x XML), QIF, CAMT.053 (ISO 20022) and CSV: read with the
**profile of its bank** when its header is that of a known export (see
[Features](fonctionnalites.md#import-de-releves-bancaires)), otherwise separator detected
and columns guessed from the header (date or transaction date, label, amount or debit
and credit). The day/month order of dates is inferred from the file; the transaction type,
from the label (`CB`, `PRLV`, `VIR`, `RETRAIT`…) or, failing that, from the file.

Returns `format` (`ofx`, `qif`, `camt`, `csv`), `bank` (bank whose profile read a CSV:
`id` and `name`, for example `{"id": "boursorama", "name": "Boursorama / BoursoBank"}`;
`null` for a CSV with guessed columns or another format), `account` (account number or IBAN read from
the file, otherwise `null`), `from`, `to`, `counts` (`new`, `known`, `duplicate`) and `rows`:
`index`, `date`, `rdate`, `amount`, `label` (cleaned as during synchronization), `type`,
`status` and, for a likely duplicate, `match` (`id`, `date`, `label` of the existing
transaction).

| Status | Meaning |
|---|---|
| `new` | New transaction |
| `known` | Same UUID as an existing transaction: it would be merged. Identical lines in the file are as many transactions (each with its own UUID) |
| `duplicate` | Same amount as an existing transaction within 3 days (date or actual date): probably the same one, synchronized with another label |

Errors: `400` (invalid base64, empty file), `413` (file too large, more than 5,000
transactions), `422` (unrecognized format, CSV columns not found, invalid XML).

### `POST /transaction/import` { #post-transactionimport }

Same parameters, plus `selected` (optional): the `index` of the lines to import; by
default, the `new` lines. `known` lines are never imported. The transactions
belong to the logged-in user, then the categorization rules apply
whatever their date. Returns `imported`, `categorized`, `from` and `to` (period of the
chosen lines).

```bash
curl -X POST https://exemple.fr/api/transaction/import -H "Authorization: Bearer $TOKEN" \
     -H "Content-Type: application/json" \
     -d "{\"file\": \"$(base64 -w0 releve.ofx)\", \"filename\": \"releve.ofx\"}"
```

### `PUT /transaction/category` { #put-transactioncategory }

`id`, `category`: assigns the category and checks off the transaction (`autoPointed=false`
to leave it unchecked).

### `PUT /transaction/pointed` { #put-transactionpointed }

`id`, `pointed` (boolean, default `true`): marks the transaction as checked or not.

```bash
curl -X PUT https://exemple.fr/api/transaction/pointed -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' -d '{"id":42,"pointed":false}'
```

### `DELETE /transaction` { #delete-transaction }

`id`: deletes the transaction (`404` if it does not exist). Its `uuid` is remembered
(`bank_transaction_deleted` table): the synchronization of its account and the import of a
statement ignore it, it does not come back (in an import preview, the line is `known`
with `deleted: true`).

```bash
curl -X DELETE "https://exemple.fr/api/transaction?id=42" -H "Authorization: Bearer $TOKEN"
```

## Budgets and trends { #budgets-et-tendances }

### `GET /budget` { #get-budget }

`month`, `year`, `category` (parent category, `0` = root). For each category:
`id`, `name`, `type`, `icon`, `color`, `budget`, `consummed` (absolute sum of the
transactions of the category and its subcategories), `progress` (%), `children`
(number of subcategories), `children_budget` (sum of their budgets) and `budget_mode`:
`own` (amount set for the category) or `children` (category with subcategories and
no amount set: `budget` is then the sum of the subcategories' budgets).

### `GET /budget/flow` { #get-budgetflow }

`month`, `year` (current month by default): money flow of the month, for the
"Money flow of the month" diagram. Amounts are grouped by top-level category; the
`HORS-BUDGET` categories (internal transfers) are excluded, except the savings category
(`savings_category`); uncategorized transactions are kept.

| Field | Description |
|---|---|
| `month` | `YYYY-MM` |
| `income`, `expenses` | Income and expenses by top-level category: `id`, `name`, `color`, `icon`, `amount` (positive), from largest to smallest |
| `savings` | Net amount put into the savings category and its subcategories |
| `totalIncome`, `totalExpenses` | Total income and total expenses |
| `balance` | Remainder: income − expenses − savings (if positive); negative in case of a deficit |

### `GET /budget/flow/transactions` { #get-budgetflowtransactions }

Transactions of a node of the flow diagram, newest to oldest, with
the same classification as `GET /budget/flow`.

| Parameter | Required | Description |
|---|---|---|
| `kind` | yes | `income`, `expense` or `savings` |
| `categories` | no | Comma-separated top-level category identifiers (`0` = uncategorized); all by default. Ignored for `savings` |
| `month`, `year` | no | Current month by default |

Returns the columns of `bank_transaction` and `top` (top-level category).

### `POST /budget` { #post-budget }

`category`, `amount` (required), `month`, `year` (current month by default): creates or
updates the category's budget for the month. For a category with subcategories, the
amount replaces the sum of their budgets; `0` reverts to that sum.

### `POST /budget/copy` { #post-budgetcopy }

`month`, `year` (target month, current month by default), `overwrite` (`false` by default):
copies the previous month's budgets to the target month. Without `overwrite`, only the
categories with no budget in the target month are copied; with `overwrite`, all the
previous month's budgets replace those of the target month. Returns `month`, `source` (previous
month, `YYYY-MM`), `copied` (budgets written) and `overwrite`.

### `GET /budget/trends` { #get-budgettrends }

| Parameter | Required | Description |
|---|---|---|
| `months` | no | Number of months (1 to 240, default 24) |
| `offset` | no | Number of months between the current month and the last month of the period (0 to 240, default 0). Used for comparisons: `offset=months` for the previous period, `offset=12` for the same period last year |

Returns one item per month (oldest to newest, empty months included):

| Field | Description |
|---|---|
| `month` | `YYYY-MM` |
| `debit` | Expenses of the month, off-budget categories excluded (positive amount) |
| `credit` | Income of the month, off-budget categories excluded |
| `offBudget` | Balance of the `HORS-BUDGET` categories (e.g. transfers to savings) |
| `planned` | Sum of the budgets of the top-level categories (amount set, or sum of the subcategories) |
| `savings` | Net amount put into the savings category (`savings_category`, `Epargne` by default) and its subcategories, positive when saving |

```bash
curl "https://exemple.fr/api/budget/trends?months=6&offset=12" -H "Authorization: Bearer $TOKEN"
```

```json
[{ "month": "2025-05", "debit": 2130.37, "credit": 5114, "offBudget": -500, "planned": 2330, "savings": 2983.63 }]
```

### `GET /budget/trends/series` { #get-budgettrendsseries }

Monthly trend of a category or of an insight, for the custom charts of the Trends tab.

| Parameter | Required | Description |
|---|---|---|
| `type` | yes | `category` or `insight` |
| `id` | yes | Id of the category or of the insight (positive integer) |
| `months` | no | Number of months (1 to 240, default 24) |
| `offset` | no | Number of months between the current month and the last month of the period (0 to 240, default 0) |

Returns one item per month (oldest to newest, empty months at `0`):

| Field | Description |
|---|---|
| `month` | `YYYY-MM` |
| `amount` | Category: total of its transactions and of those of its subcategories, positive for spending in a `DEBIT` category (a refund lowers it), raw for the other types. Insight: value of its query for the month (`0` when it fails) |
| `planned` | Category: budget of the month as in the Budget tab (amount set, or sum of the subcategories), `null` without a budget. Insight: always `null` |

`400` for an invalid type or id, `404` for an unknown category or insight.

```bash
curl "https://exemple.fr/api/budget/trends/series?type=category&id=1&months=36" -H "Authorization: Bearer $TOKEN"
```

```json
[{ "month": "2026-09", "amount": 412.5, "planned": 450 }, { "month": "2026-10", "amount": 127.4, "planned": null }]
```

### `GET /budget/trends/categories` { #get-budgettrendscategories }

Monthly trend of every category of a type, to compare them on one chart ("By category"
card of the Trends tab).

| Parameter | Required | Description |
|---|---|---|
| `type` | no | `DEBIT` (default), `CREDIT` or `HORS-BUDGET` |
| `level` | no | `top` (default): top-level categories, subcategories counted in their parent (the type is the one of the parent); `sub`: every category on its own |
| `months` | no | Number of months (1 to 240, default 24) |
| `offset` | no | Number of months between the current month and the last month of the period (0 to 240, default 0) |

Returns `months` (the `YYYY-MM` months of the period, oldest to newest) and `categories`:
`id`, `name`, `icon`, `color`, `parent_category`, `total` of the period and `amounts` (one
amount per month of `months`, empty months at `0`). The amounts are positive for spending
in a `DEBIT` category (a refund lowers them), raw for the other types. Only the categories
with an amount in the period are returned, largest first; category `0` (uncategorized) is
left out.

`400` for an unknown type or level.

```bash
curl "https://exemple.fr/api/budget/trends/categories?type=DEBIT&level=top&months=3" -H "Authorization: Bearer $TOKEN"
```

```json
{
  "months": ["2026-08", "2026-09", "2026-10"],
  "categories": [
    { "id": 4, "name": "Logement", "icon": "home", "color": "blue", "parent_category": 0, "total": 2850, "amounts": [950, 950, 950] },
    { "id": 1, "name": "Alimentation", "icon": "restaurant", "color": "green", "parent_category": 0, "total": 247.4, "amounts": [0, 120, 127.4] }
  ]
}
```

### `GET /budget/trends/charts` { #get-budgettrendscharts }

Custom charts of the Trends tab, shared by the household, in display order: `type`, `id`,
`name`, `icon`, `color` of the category or of the insight. Charts whose category or insight
was deleted are left out.

### `PUT /budget/trends/charts` (administrator) { #put-budgettrendscharts-administrateur }

Replaces the list. Body: `{"charts": [{"type": "category", "id": 1}, {"type": "insight", "id": 2}]}`
(12 charts at most, no duplicates, existing categories and insights; otherwise `400`). Returns
the saved list, as `GET` does. It is stored in the `trend_charts` setting
(e.g. `["c1","i2"]`), without a migration.

## Insights { #insights }

### `GET /budget/insights` { #get-budgetinsights }

`month`, `year`: value of each insight for the month (`id`, `name`, `color`, `icon`,
`amount`). A failing query gives `0` without blocking the others.

### `GET /budget/insights/history` { #get-budgetinsightshistory }

`year`: for each insight, the `history` list of amounts month by month.

### `GET /insight`, `POST /insight`, `PUT /insight`, `DELETE /insight?id=` — administrator { #get-insight-post-insight-put-insight-delete-insightid-administrateur }

Management of insights, with their SQL query:

| Parameter | Required | Description |
|---|---|---|
| `id` | `PUT`, `DELETE` | Insight to update or delete (`404` if unknown) |
| `name` | yes | 1 to 20 characters |
| `color` | yes | `red`, `orange`, `amber`, `lime`, `green`, `teal`, `cyan`, `blue`, `indigo`, `purple`, `pink`, `brown` or `gray` |
| `sql` | yes | `SELECT … AS amount` query (500 characters at most); `{month}` and `{year}` are replaced by the requested month |
| `icon` | no | Material icon (`[a-z0-9_]`); chosen from the name if empty |

The query must be a single `SELECT` returning an `amount` column, with no `;`,
comment or `\`, no write, administration, file or
delay keyword, and it only reads the budget tables (`bank_transaction`,
`bank_transaction_category`, `bank_transaction_category_keyword`, `budget`,
`bank_account`, `bank_backend`, without a database name). It is tested on the current month before
being saved (`400` with the cause otherwise) and run in a read-only
transaction, in 5 seconds at most (beyond that: `400` when saving, `0` in the lists). `POST` and `PUT` return the insight with
the amount for the current month.

### `POST /insight/check` — administrator { #post-insightcheck-administrateur }

`sql`, `month`, `year` (current month by default): checks a query without saving it
(same checks), while typing. Returns `{"valid": true, "amount": …}` or
`{"valid": false, "error": "…"}` (always `200`).

## Categories and rules { #categories-et-regles }

### `GET /category` { #get-category }

All categories: `id`, `name`, `parent_category`, `type` (`DEBIT`, `CREDIT`,
`HORS-BUDGET`), `icon`, `color`.

### `POST /category`, `PUT /category`, `DELETE /category?id=` — administrator { #post-category-put-category-delete-categoryid-administrateur }

Creates or updates a category (`name`, `type`: `DEBIT`, `CREDIT` or `HORS-BUDGET`,
`parent_category`, `icon`, `color`; `id` for an update) or deletes it. `409` if the
name already exists or if the category to delete has subcategories, `400` for the default
category (0) or a parent that is not a main category. On deletion, the
transactions move to category 0 and the category's budgets and rules are deleted.

### `GET /category/keyword` { #get-categorykeyword }

Automatic categorization rules: `id`, `keyword`, `category`, `category_name`.

### `POST /category/keyword` — administrator { #post-categorykeyword-administrateur }

`keyword` (1 to 60 characters, saved in uppercase), `category`: adds a rule.
Errors: 400 empty keyword, 404 unknown category, 409 keyword already exists.

```bash
curl -X POST https://exemple.fr/api/category/keyword -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' -d '{"keyword":"decathlon","category":9}'
```

### `PUT /category/keyword` — administrator { #put-categorykeyword-administrateur }

`id`, `keyword`, `category`, `notify`: updates a rule (`409` if the keyword already exists).
`POST /category/keyword` also accepts `notify`: each new synchronized transaction
that contains the keyword then sends a notification to every user of the household.

### `GET /category/keyword/count?id=` — administrator { #get-categorykeywordcountid-administrateur }

Number of transactions whose label contains the keyword (`matching`), including those already
in the rule's category (`categorized`).

### `DELETE /category/keyword` — administrator { #delete-categorykeyword-administrateur }

`id`: deletes a rule (404 if unknown).

### `POST /category/keyword/apply` — administrator { #post-categorykeywordapply-administrateur }

Applies every rule to every transaction in the database, including those already
categorized (the first rule found in the label wins). Returns
`{ "updated": <number of transactions whose category changed> }`. Creating or updating a
rule (`POST`/`PUT /category/keyword`) also applies it immediately to the whole history and
returns `applied`, the number of recategorized transactions.

## Devices { #appareils }

### `GET /device` { #get-device }

Devices of the logged-in user: `id`, `user_id`, `name`, `token`, `platform` (`ios` or
`android`; `ios` until the `2026-10-23_device_platform` migration is applied),
`lastLogin`, `model`, `os_version`, `app_version` (`null` if the app did not send them
or until the `2026-10-25_device_model` migration is applied).

### `GET /device/link`

Address of the API given to the mobile apps by the QR code of **My devices**:
`{ "server": "https://carbure.example.com/api/" }`, that is `public_url` followed by
`/api/`, or `null` without this setting (the portal then uses its own address).

### `DELETE /device` { #delete-device }

`id`: deletes a device of the logged-in user (it will no longer receive
notifications). 404 if the device does not exist or belongs to another user.

### `GET /user/browser`, `DELETE /user/browser?id=`

Web browsers from which the authenticated user logged in to the portal (a login without
a `device` object), the last used first: `id`, `label` (User-Agent), `created_at` (first
login), `last_login`, `current` (`true` for the browser of the request). An HttpOnly
cookie `carbure_browser` (sent only to the `/user` routes) recognizes the browser; the
database keeps only its SHA-256 hash; a browser unused for 6 months is forgotten. It is
not a device: it receives no notification and does not exempt from two-factor
authentication. `DELETE` forgets it (`404` if it does not exist or belongs to another
user) without closing its session: it shows up again at its next login. Empty list as
long as the migration `2026-10-26_user_browser` is not applied.

### `POST /device/push` { #post-devicepush }

Sends a test notification to every device of the logged-in user, through the
Carbure Push service. Returns the result for each token: `success`, `http_code`,
`status` (`sent`, `unregistered`, `invalid_token`, `forbidden`, `device_limit`, `error`),
`response` and, if it was not sent, `error` (without an API key: `Push service not
configured (push_api_key)`). An `unregistered` device (app deleted, token
expired) is deleted.

## AI agents (MCP) { #agents-ia-mcp }

### `GET /token`, `POST /token`, `DELETE /token?id=` { #get-token-post-token-delete-tokenid }

Access tokens of the logged-in user, for the MCP server. `POST` (`name`: 1 to 50
characters; `days`: `30`, `90`, `365`, or `0`/empty for no expiration) returns the token
(`token`, `cbt_` prefix) **only once**, with `id`, `name`, `token_hint` and
`expires_at`; `409` beyond 20 tokens. The list only gives `id`, `name`,
`token_hint`, `created_at`, `last_used_at`, `expires_at` (`null` = no expiration) and
`expired`. `DELETE` revokes a token (`404` if it does not exist or belongs to another
user).

### `GET /mcp/settings`, `PUT /mcp/settings` { #get-mcpsettings-put-mcpsettings }

`{"enabled": true|false}`: state of the MCP server (disabled by default). `PUT` (`enabled`)
enables or disables it — administrator.

### `POST /mcp` — public (access token) { #post-mcp-publique-jeton-dacces }

Read-only MCP server (JSON-RPC 2.0, Streamable HTTP transport), authenticated with
`Authorization: Bearer <token>` (or `?token=`). `GET /mcp` answers `405`. See
[AI agents (MCP)](mcp.md).

### OAuth 2.1 for AI agents — public (unless stated otherwise) { #oauth-21-des-agents-ia-publiques-sauf-indication }

For agents that only take the URL of the MCP server (Claude web, Desktop, mobile).
Responses and errors in the OAuth format (`{"error", "error_description"}`), not in the API
format.

| Route | Purpose |
|---|---|
| `GET /.well-known/oauth-protected-resource` (and `…/api/mcp`) | MCP server metadata (RFC 9728): `resource`, `authorization_servers` |
| `GET /.well-known/oauth-authorization-server` | OAuth metadata (RFC 8414): endpoints, `S256`, client authentication methods |
| `POST /api/oauth/register` | Dynamic registration (RFC 7591): `redirect_uris` (HTTPS, or HTTP on `localhost`), `client_name`, `token_endpoint_auth_method` (`none`, `client_secret_post`, `client_secret_basic`); `201` with `client_id` (and `client_secret`) |
| `GET /api/oauth/authorize` | `response_type=code`, `client_id`, `redirect_uri`, `state`, `code_challenge` (`S256`): `302` to the portal's consent page, or to the agent with `error=`; `400` if the client or the redirect URI is unknown |
| `GET /api/oauth/client?request=` — JWT | Agent name and redirect host, for the consent page |
| `POST /api/oauth/approve` — JWT | `request`, `approved`: `{"redirect"}` to the agent, with the code (5 minutes, single use) or `error=access_denied` |
| `POST /api/oauth/token` | Form or JSON: `grant_type=authorization_code` (`code`, `redirect_uri`, `client_id`, `code_verifier`) or `refresh_token`; `{"access_token", "token_type": "Bearer", "expires_in", "refresh_token"}` |

They all answer `403` (`access_denied`) when the MCP server is disabled.

## Backup { #sauvegarde }

The portal builds the ZIP of an export and reads the ZIP of an import: the API only exchanges
JSON. Datasets (in dependency order): `categories`, `rules`, `budgets`,
`insights`, `transactions`, `deleted_transactions`. The owner of a transaction
(`user`) and the author of a deletion (`user_id`) are written as their
username. Never exported: users, passwords, tokens, OAuth, devices,
settings, bank connections and accounts, budget alerts already sent.

### `GET /backup` — administrator { #get-backup-administrateur }

The datasets that can be backed up and their row count: `[{"name":"categories","count":12}, …]`.

### `GET /backup/export` — administrator { #get-backupexport-administrateur }

Optional parameter `datasets`: comma-separated names (all by default; `400`
for an unknown name). Response: `format` (`carbure-backup`), `version` (1), `created_at`,
`carbure` (version), `schema` (latest migration), `counts` and `datasets` (the rows of
each dataset, with the columns of its table).

### `POST /backup/import` — administrator { #post-backupimport-administrateur }

Body: `{"datasets": {"rules": [{"id":1,"keyword":"EDF","category":3,"notify":false}], …}}`.
**Deletes and replaces** the rows of each dataset provided (an empty list empties it); the
others are kept. Every row is checked before anything is deleted (types,
lengths, dates, unique identifiers, hierarchy, category icon and color,
query, color and icon of insights as when they are saved), then the import runs in a single transaction: `400` with the
details (dataset, row, column) and nothing is changed if a row is invalid, if a
database constraint is violated, or if a kept or imported dataset refers to a
missing category. Category 0 (default) is recreated if it is missing; the budget
alerts of categories that no longer exist are deleted. An unknown username becomes
the administrator running the import; a deletion without an author (`user_id` set to `null`) stays that way.
The portal rejects a file whose format version is newer than its own. Response: `{"imported": {"rules": 1}}`. Body limited to 32 MB
by nginx (2 MB for the other routes).

## System { #systeme }

### `GET /health` — public { #get-health-publique }

State of the instance for the Docker `HEALTHCHECK` and monitoring tools, with no
authentication and no data: `{"status":"ok","version":"1.2.0","database":"ok","schema":"<schema version>","min_mobile_version":"1.0","currency":"EUR"}`,
`500` if the database does not respond, `{"status":"setup","version":"…","min_mobile_version":"1.0"}` as long as Carbure is
not installed. `version` is the Carbure version (`VERSION` file, `dev` outside a release).
`currency` is the currency of the amounts (setting `currency`, ISO 4217 code, `EUR` by default).
`min_mobile_version` is the oldest version of the iOS app (its `MARKETING_VERSION`)
that the server supports: an older app asks its user to update
it. It is set in the code (`Carbure\Lib\MobileApp::MIN_VERSION`) and is only raised
when an API change can no longer be read by older versions of the app,
once the new version is published on the App Store.
On a demo instance (`demo` setting, `ltoinel/carbure:demo` image), the response
also contains `"demo":true`: the portal then shows the public account (`demo` / `carbure`)
on the login screen. The field is absent on a regular instance.

### `GET /system/logs` — administrator { #get-systemlogs-administrateur }

Log files of the instance, newest first: `[{name, size, modified}]`.

### `GET /system/logs/retention` — administrator { #get-systemlogsretention-administrateur }

Retention period of the log files: `{"days": 90}` (`0`: kept indefinitely).
Older files are deleted at the end of the bank synchronization
(`log_retention_days` setting).

### `GET /system/logs/entries` — administrator { #get-systemlogsentries-administrateur }

Entries of a file, newest first. Parameters: `file` (name returned by
`/system/logs`), optional `level` (minimum level: `DEBUG`, `INFO`, `WARN`, `ERROR`),
optional `search` (text or request identifier), `limit` (1 to 1000, 200 by default).
Response: `{file, entries: [{time, level, uid, caller, message, ip, user, method, path}], truncated}`
(`ip`, `user`, `method` and `path`: entries in JSON format, absent from older
files; the context of an entry follows its message); only the
last 2 MB are read (`truncated`) and tokens are masked. 400 for an invalid file
name, 404 if it does not exist.

### `GET /system/schema` — administrator { #get-systemschema-administrateur }

Database schema version and migrations to apply:
`{"version": "2026-10-13_base", "pending": []}`.

### `POST /system/migrate` — administrator { #post-systemmigrate-administrateur }

Applies the pending migrations (**Update** button in the portal):
`{"version": "…", "applied": ["…"]}`; `500` with the cause if a migration fails (the
following ones are not run).

### `POST /system/jwt-secret` — administrator { #post-systemjwt-secret-administrateur }

Replaces the `jwtsecret` of the configuration with a random value: every session
(portal and iOS app) ends. `GET /system/schema` reports `weakJwtSecret`
when the secret is the sample one (or too short): the portal then offers to
renew it.

### `GET /system/config` — administrator { #get-systemconfig-administrateur }

The configuration file, by section: `file` (path in the instance), `writable`, and
`sections` (`name`, `settings`). Each setting: `key`, `value` (masked for secrets;
`true`/`false` for a toggle; a list for `regex_label`), `set`, `secret`,
`editable`, `kind` (`select`, `bool`, `number`, `text`, `url`, `email`, `password`, `list`) and, depending on the type,
`options`, `min`, `max`. Editable settings missing from the file are listed with
`set: false`.

### `PUT /system/config` — administrator { #put-systemconfig-administrateur }

`values`: the new values by key, only among the editable settings
(`log_level`, `log_retention_days`, `savings_category`, `public_url`, `woob_transactions`, `woob_logging`,
`woob_debug`, `woob_auto_update`, `woob_2fa_wait`, `woob_2fa_retries`, `push_api_key`, `smtp_host`, `smtp_port`, `smtp_encryption`, `smtp_auth`,
`smtp_username`, `smtp_password`, `smtp_from`, `smtp_from_name`). All values are checked before anything
is written (`400` otherwise, file unchanged); the old file is kept as `.bak`. Returns the
configuration as `GET` does.

### `GET /system/sync` — administrator { #get-systemsync-administrateur }

`reveal` (optional, `false` by default): `url` of `/api/bank/sync`, `token` (the
`sync_token`, masked unless `reveal=true`; `null` if there is none), `masked` and
`accounts` (the followed accounts, for `?account=`) and `header` (`X-Sync-Token`, the header
that carries the token). The request to schedule is
`curl -sN -H "<header>: <token>" "<url>"`: the token is never in the URL.

### `POST /system/smtp-test` — administrator { #post-systemsmtp-test-administrateur }

`to` (optional, the administrator's address by default): sends a test email with
the SMTP server of the configuration, in the language of the user with that address (otherwise
the administrator's). Returns `{"sent": true, "to": "…"}`; `400` with no
SMTP server configured or no recipient, `502` if the server is unreachable or rejects
the email (its response is in the error message).

### `GET /system/push` — administrator { #get-systempush-administrateur }

The instance's push notification service, its API key and the administrator's
consent:
`{"url": "https://api.carbure.org", "official": true, "registered": true, "api_key": "cpk_…", "consent": {"at": "2026-10-17T08:00:00Z", "url": "https://api.carbure.org"}, "consent_valid": true, "config_writable": true}`.
`url` is the relay used (always `https://api.carbure.org`), `official` whether it is
the official service (always true), `registered` whether an API key is configured,
`api_key` the key (`""` when not registered; restricted to administrators, never in
`GET /system/config`), `consent` the last recorded consent (`null` if there is
none), `consent_valid` whether it was given for this relay and `config_writable` whether the
configuration file can receive the key.

### `GET /system/push-status` — administrator { #get-systempush-status-administrateur }

The state of the push notification service, checked with the relay on each call:
`{"relay": {"reachable": true, "status": "ok", "version": "1.0.0", "error": null}, "key": {"state": "valid", "key_id": "0123456789abcdef", "devices": 3, "used_today": 12, "limits": {"notifications_per_day": 500, "devices": 20}}}`.

- `relay`: response of `GET /v1/health`; `status` is `ok`, `error` (the relay responds
  but not "ok": `error` gives the reason) or `unreachable` (`reachable` set to `false`).
- `key`: response of `GET /v1/keys/current` with the instance's key; `state` is
  `valid` (with `key_id`, `devices`, `used_today`, `limits`), `invalid` (rejected:
  revoked or unknown), `none` (no key) or `unknown` (relay unreachable or unexpected
  response), with `error` for these last two cases and `invalid`.

### `POST /system/push-register` — administrator { #post-systempush-register-administrateur }

Registers the instance with the push notification service (`https://api.carbure.org`): solves its proof of work, obtains an API key and writes it to
`push_api_key`. With a key already configured, **renews** it (devices keep
receiving notifications). The key is not returned here (`GET /system/push` gives it):
`{"registered": true, "renewed": false, "key_id": "…", "limits": {…}}`. `502` if the service
is unreachable or refuses, `429` beyond its registration limits; the configuration
file must be writable (checked before the call).

Parameter `consent` (`true`): the administrator agrees that notifications (device
tokens, title and text) go through this relay. **Required** for a registration,
and for a renewal if the recorded consent applies to another relay (the one
of an older version): otherwise `400`. It is recorded (date and relay address, `settings`
table) and returned by `GET /system/push`.

### `POST /system/push-test` — administrator { #post-systempush-test-administrateur }

Sends a test notification to the administrator's devices, in their language:
`{"sent": 1, "devices": 2, "results": [{"device": "iPhone", "sent": true, "status": "sent", "error": null}, …]}`
(without the tokens). `400` with no API key or no device.

### `POST /system/sync-token` — administrator { #post-systemsync-token-administrateur }

Writes a new random `sync_token` to the configuration and returns it: `{"token": "…"}`.
Scheduled tasks must use the new one.

## Setup wizard { #assistant-dinstallation }

As long as `data/conf/prod.ini` does not exist, the API only answers these routes (all others
return `503` with `"setup": true`):

| Route | Purpose |
|---|---|
| `GET /setup` | State of the wizard: `codeRequired`, `dbPasswordFromEnvironment` and default values (`db_host`, `db_port`, `db_name`, `db_user`, `admin_user`, `language`) taken from the Docker environment |
| `POST /setup/database` | Tests the connection (`db_host`, `db_port`, `db_name`, `db_user`, `db_password`) and describes the installation: `state` (`none` = empty database, `current` = up to date, `outdated` = migrations to apply), `version`, `pending`, `hasAdmin` |
| `POST /setup/install` | Creates or migrates the schema, creates the first administrator if there is none (`admin_user`, `admin_password` of at least 8 characters, `admin_email`, `language`) and writes the configuration; `409` without `backup_confirmed` when migrations need to be applied. On an empty database, `starter_categories` (optional) adds the categories and rules from `sql/starter.json`, and `starter_insights` its insights |
| `GET /health` | `{"status":"setup","version":"…","min_mobile_version":"…"}` |

From the Internet, or if `data/conf/setup.code` exists, `POST` requests require the
`code` parameter (setup code), otherwise `403`. See [Security](securite.md#assistant-dinstallation).

## OpenAPI specification { #specification-openapi }

`swagger/swagger.php` generates an OpenAPI 3 specification by reflection on the
`#[ApiRoute]` attributes; `swagger/index.html` displays it with Swagger UI, also in the
portal (**Administration → API**), authenticated with the administrator's session. The
specification paths start with `/api` (server `/`): **Try it out** calls
`/api/...` on the server that serves Swagger UI. `swagger/carbure.css` darkens the colors of
Swagger UI's light theme (WCAG AA contrast); its dark theme follows the portal's, or
the system preference when Swagger UI is opened on its own.
