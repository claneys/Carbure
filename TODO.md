# TODO

Remaining tasks on Carbure. A finished task is removed from this list (the Git history
keeps track of what was done).

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

- [ ] Budget alerts only check the current month (an import of past months alerts
      nothing) and the spending as the Budgets screen computes it (absolute value of the
      sum of the month, refunds included): if alerts on past months are wanted, add a
      separate history check instead of widening `BudgetAlert::check()`.

- [ ] Strong authentication: handle the validations by **code** (SMS OTP, woob
      `BrowserQuestion`), which wait for input on stdin (closed today by
      `tools/woob-tty.py`); and offer the interactive mode to "Find my accounts in woob" /
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
- [ ] The PHP notifications and emails (APNs pushes, e-mails) read the same translation files
      as the portal (`portal/i18n/<locale>.json`) instead of their `$french ? … : …` strings
      (separate issue to create).
- [ ] Once the branches started before the split of the translations (#139) are merged
      (email notifications, theme toggle, budget alerts, PWA), remove
      `tools/i18n-split.mjs` (conversion of the old `portal/i18n.js`, see
      docs/developpement.md) and its section of the docs.
