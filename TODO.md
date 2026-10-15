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

- [ ] **GitHub settings** (owner, after the merge of the workflow hardening, #125): in
      **Settings → Actions → General**, tick "Require actions to be pinned to a full-length
      commit SHA" and restrict the allowed actions to those of GitHub, verified creators
      and the list used by the workflows (`shivammathur/setup-php`, `hadolint/hadolint-action`,
      `aquasecurity/trivy-action`, `gitleaks/gitleaks-action`, `docker/*`); in the ruleset
      of `main`, add the check "Conventional Commits title" (workflow PR title) to the
      required checks and set **GitHub Actions** as the source of every required check (not
      "any source"). After the next release, check on the NAS
      `gh attestation verify oci://docker.io/ltoinel/carbure:<version> --owner ltoinel`.

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
- [ ] The account is not part of the UUID: the same operation (real date, label, amount) on
      two synchronized accounts is stored only once. Adding it changes every UUID (migration,
      or unique key `(account_number, uuid)`); only worth doing if accounts of the household
      can have identical operations.
