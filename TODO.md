# TODO

Remaining tasks on Carbure. A finished task is removed from this list (the Git history
keeps track of what was done).

- [ ] **Production — push notifications moved to Carbure Push (#170), BEFORE upgrading
      Carbure**, otherwise the push notifications stop (Carbure no longer reads `[apns]` nor
      the `.p8` key):
      1. deploy the relay (private repo `ltoinel/Carbure-push`, README "Déploiement"): its
         own container with the APNs `.p8` key and, for Android later, the Firebase service
         account; serve it in HTTPS on `https://api.carbure.org` (the default `push_url`
         since 1.6.0; with Carbure 1.5.0, whose default is `https://push.carbure.org`,
         set `push_url` to `https://api.carbure.org` in the settings);
      2. check `bin/push-admin check` and `GET /v1/health` on the relay;
      3. upgrade Carbure; the migration `2026-10-23_device_platform` (`devices.platform`,
         default `ios`) is applied at container start (the code works before it);
      4. **Administration → Settings → Push Notification Mobile**: set `push_url` if
         not the default, click **Register the instance**, accept the consent dialog (#201;
         recorded in the `settings` table) (stores `push_api_key`), then
         **Send a test notification** to an iPhone;
      5. remove the `[apns]` section and `data/conf/certs/*.p8` from the NAS (no longer used);
      6. later: a release workflow publishing the image of Carbure Push to a registry.

- [ ] **Demo image (#219)**: `ltoinel/carbure:demo` and `ltoinel/carbure:<version>-demo` are
      pushed by the release workflow from the next release (1.6.0): until then the
      `docker run … ltoinel/carbure:demo` command of the README and of the docs fails. After
      the release, check `docker run --rm -p 8080:80 ltoinel/carbure:demo` on amd64 and
      arm64, and mention the demo tag in the overview of the Docker Hub repository.

- [ ] **Push relay — release the device bindings**: Carbure never calls
      `DELETE /v1/devices/{token}` of the relay. A device deleted from an instance (or
      moved to another instance) stays bound to the key of the first instance, so a second
      instance gets `forbidden` for it until `binding_ttl_days` (60 days) without push or
      an operator `devices:release`. Call it, best effort, when a device is deleted.
- [ ] **Push relay — key renewal answer lost**: `POST /system/push-register` with a key
      renews it; if the answer of `POST /v1/keys/rotate` is lost (timeout after the relay
      committed), the old key is revoked and the new one unknown: register again, and ask
      the operator to `devices:release` the devices (or wait 60 days).

- [ ] **Android app**: the backend is ready (devices with a `platform`, login
      `device.platform=android`, FCM provider in Carbure Push); to do: the app itself, the
      Firebase project and its service account on the relay.

- [ ] **Production** (device model, #218): the migration `2026-10-25_device_model`
      (nullable `devices.model`, `os_version`, `app_version`) is applied at container start;
      until it is, these fields are ignored at login and returned as `null`. It also sets
      `platform = 'android'` on the devices whose token is not an APNs one (Android devices
      stored as iOS before).
- [ ] **Android app** (#218): send `platform: "android"` in the `device` object of
      `POST /user/login` and `POST /user/login/mfa` (the server now deduces it from the FCM
      token for the released versions that do not), plus `model` (e.g. "Google Pixel 8"),
      `os_version` ("Android 15") and `app_version` ("1.0").
- [ ] **iOS app** (#218): send `model` (hardware model, e.g. "iPhone16,1"), `os_version`
      ("iOS 26.0") and `app_version` ("1.2") in the `device` object of `POST /user/login`
      and `POST /user/login/mfa` (optional: unknown when absent).

- [ ] **Production**: test the connection of Claude to the MCP server from claude.ai
      (Settings → Connectors → Add custom connector). The Docker image applies the OAuth
      migration at startup and routes `/.well-known/oauth-*`; set `public_url`
      (**Administration → Settings** tab) if the HTTPS proxy of the NAS does not send
      `X-Forwarded-Proto`.

- [ ] **Production**: after the deployment, check on the Logs page that the IP of the
      entries is the client's, not the one of the DSM reverse proxy (it must send
      `X-Forwarded-For`). Set `log_retention_days` (missing from existing configurations:
      logs kept forever) in **Administration → Settings**.

- [ ] **Production** (sync token in the URL deprecated, #149): if a scheduled task of the
      NAS (DSM Task Scheduler, cron) calls `/api/bank/sync?token=…`, replace it by
      `curl -fsS -H "X-Sync-Token: <sync_token>" <url>/api/bank/sync > /dev/null` (the
      command of **Administration → Synchro**). Then check that the Logs page no longer shows
      the warning "The sync token passed in the URL is deprecated". Later: once no
      scheduler uses it, remove `?token=` from `GET /bank/sync` (the MCP server keeps its
      `?token=` for the agents that cannot send a header).

- [ ] **Production** (API sub-tab, #163): after the deployment, open **Administration → API**
      and check that Swagger UI is shown. A blank frame means the DSM reverse proxy adds its
      own `X-Frame-Options: DENY` (or `frame-ancestors 'none'`) to `/swagger/`: let it pass
      the headers of the container (`SAMEORIGIN`, `frame-ancestors 'self'`).

- [ ] **GitHub settings** (owner, after the merge of the workflow hardening, #125): in
      **Settings → Actions → General**, tick "Require actions to be pinned to a full-length
      commit SHA" and restrict the allowed actions to those of GitHub, verified creators
      and the list used by the workflows (`shivammathur/setup-php`, `hadolint/hadolint-action`,
      `aquasecurity/trivy-action`, `gitleaks/gitleaks-action`, `docker/*`); in the ruleset
      of `main`, add the check "Conventional Commits title" (workflow PR title) to the
      required checks and set **GitHub Actions** as the source of every required check (not
      "any source"). After the next release, check on the NAS
      `gh attestation verify oci://docker.io/ltoinel/carbure:<version> --owner ltoinel`.

- [ ] **Production**: the migration `2026-10-20_user_notification` (table of the
      notification channels chosen by each user) is applied at container start. To send the
      notifications by email, set the `[smtp]` settings in **Administration → Settings**
      (existing `prod.ini` files have no `[smtp]` section: it is added on save), then use
      **Send a test email**. Until then nothing changes: notifications are pushed only.

- [ ] **Production**: the migrations `2026-10-21_user_budget_threshold` (column
      `users.budget_threshold`, 100 for the existing users) and `2026-10-22_budget_alert`
      (alerts already sent) are applied at container start. Nothing changes until a user
      turns on "Budget of the month exceeded" in **My profile → Notifications** (opt-in).

- [ ] **Production**: the migration `2026-10-23_user_budget_alert_category` (column
      `users.budget_alert_category`, NULL = all the budgets for the existing users) is
      applied at container start. Until then the budget alerts watch every budget and
      choosing one in **My profile → Notifications** answers 409.

- [ ] Backup (Administration → Backup, #162): the import is limited to 32 MB of JSON (nginx
      `client_max_body_size` of `/api/backup/import`, PHP `post_max_size`) and decoded in
      memory (`memory_limit` 256M). Measured: 31 MB of JSON (95,000 transactions) reach
      about 230 MB of peak memory once decoded and checked, close to the limit; the usual
      household (10,000 to 20,000 transactions, 3 to 7 MB) is far below. If a household
      needs more, send the datasets in several requests (one transaction per request would
      then no longer cover the whole import) or stream the rows. The bank accounts (labels,
      owners) and connections are not in a backup: their credentials live in woob.

- [ ] **Production** (two-factor authentication, #166): the migration `2026-10-24_user_mfa`
      (tables `user_mfa`, `mfa_recovery_code`, `mfa_trusted_browser`) is applied at
      container start; until it is, the second factor is never asked. Nothing changes until
      an administrator turns on `mfa_required` in **Administration → Settings** (off by
      default; existing `prod.ini` files get the line on save). Before turning it on: set up
      your own authenticator app from **My profile → Two-factor authentication** and keep the recovery codes (an
      administrator who loses both sets `mfa_required=false` in `data/conf/prod.ini`), and
      make sure every iPhone of the household has logged in once (a known device skips the
      code; a new one is refused, see the iOS item below).

- [ ] **iOS app** (two-factor authentication, #166): support the second step of the login.
      Send `mfa: true` to `POST /user/login`; on `{mfa: "verify"|"enroll", mfa_token, …}`
      ask the 6-digit code (or a recovery code; for `enroll`, show the `otpauth` URI /
      `secret` to add it to an authenticator app, then the `recovery_codes` returned) and
      call `POST /user/login/mfa` with `mfa_token`, `code` and the same `device` (registered
      only then). Until then, the current app logs in only from an iPhone already
      registered for that user: a new iPhone gets `403` "Two-factor authentication is
      required and this device is not known yet" while `mfa_required` is on.

- [ ] **Two-factor authentication, known devices** (#166 review): a device registered in
      `devices` skips the code, also one registered before `mfa_required` was turned on,
      and the administrator reset of the second factor does not remove the devices of the
      user (a lost iPhone stays known until the user deletes it in his profile). Once the
      iOS app supports the second step, consider removing the devices on reset (or letting
      the administrator remove them) and dropping the known-device bypass.

- [ ] Budget alerts only check the current month (an import of past months alerts
      nothing) and the spending as the Budgets screen computes it (absolute value of the
      sum of the month, refunds included): if alerts on past months are wanted, add a
      separate history check instead of widening `BudgetAlert::check()`.

- [ ] Strong authentication: handle the validations by **code** (SMS OTP, woob
      `BrowserQuestion`), which wait for input on stdin (closed today by
      `Woob::executeWithHeartbeat`); and offer the interactive mode to "Find my accounts in woob" /
      "Connect the bank" (`Woob::listAccounts`), which also fail on a bank that requires 2FA.

- [ ] MySQL connection in `utf8mb4` (`Db` does not call `set_charset`: the connection uses
      the default charset of the server). Before adding it, check in production what PHP
      receives and how accents are stored:

      ```bash
      docker compose exec carbure php -r '$c = parse_ini_file("/data/conf/prod.ini");
        $m = new mysqli($c["db_hostname"], $c["db_username"], $c["db_password"], $c["db_name"], (int)($c["db_port"] ?? 3306));
        echo $m->character_set_name(), "\n";
        foreach ($m->query("SELECT name, HEX(name) AS h FROM bank_transaction_category WHERE name LIKE \"%pargne%\"") as $r) echo $r["name"], " ", $r["h"], "\n";'
      ```

      `utf8mb4` (and `É` = `C389`): `set_charset('utf8mb4')` changes nothing, add it.
      `latin1` with `É` = `C383E280B0`: accents are stored double-encoded; a conversion
      migration is needed first, otherwise the portal would show "Ã‰pargne".
      Symptom of a latin1 server: the API answers 500 ("cannot be encoded in JSON" in the
      logs) as soon as an accented text was written by a utf8mb4 connection.
      Account names (`bank_account.label`, often accented: « Compte chèques ») are also
      covered by such a conversion; the 100-character limit is checked with `mb_strlen`.
- [ ] Greedy `PRLV SEPA` regex (`ECH/…` is not removed from the label) and `addslashes()`
      applied before a prepared statement (double escaping). Fixing them changes the labels,
      hence the UUIDs: plan an SQL migration that recomputes labels and UUIDs exactly like
      PHP (amount in PHP float format `-42.5`, first 24 **bytes** of the label for cards,
      only the rows whose current UUID matches the formula), and handle UUID collisions.
      The regex is in the `prod.ini` of each instance: fixing it in `prod.sample.ini` is not
      enough. Repetitions of an identical transaction have the UUID `md5(uuid . '#n')`
      (`Transaction::prepareAll()`): the migration must recompute them from the base UUID,
      rank by rank.
- [ ] Bank profiles of the import (`src/lib/import/banks/`) kept the reading of the former
      heuristics: the French Qonto export is booked on its operation date (the English one
      on its settlement date), Boursorama ignores its value date and `comment`. Aligning
      them changes the dates (not the UUID, made of the real date): check with real files
      first. Variants without a fixture are still read by the generic reader: BNP Paribas
      with `,`, the former N26 export in English or German (no public sample yet).
- [ ] The account is not part of the UUID: the same operation (real date, label, amount) on
      two synchronized accounts is stored only once. Adding it changes every UUID (migration,
      or unique key `(account_number, uuid)`); only worth doing if accounts of the household
      can have identical operations.
- [ ] The PHP notifications and emails (push notifications, e-mails) read the same translation files
      as the portal (`portal/i18n/<locale>.json`) instead of their `$french ? … : …` strings
      (separate issue to create).
- [ ] Spanish, Italian, German and Portuguese (#167): the branches that add portal keys
      to `fr.json`/`en.json` (profile sub-tabs, backup/restore, admin API tab, MFA…) must
      also add them to `es.json`, `it.json`, `de.json` and `pt.json` (parity test of
      `tests/portal/i18n.test.mjs`). Have the four translations proofread by native
      speakers.
- [ ] iOS app: check that a profile language `es`, `it`, `de` or `pt` (returned by
      `GET /user/me`) is handled (the app only knew `fr`/`en`).
- [ ] Savings of the Trends tab: the default `savings_category` is `Epargne`, which matches
      only the French starter category; an installation in another language (`Savings`,
      `Ahorro`, `Risparmio`, `Sparen`, `Poupança`) has to set it in Settings. Use the
      starter category id (31) or a default per language.
