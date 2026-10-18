# End-to-end tests of the portal

Playwright tests of the portal, against the real API and a MariaDB database recreated
before each test. How to run them: [docs/developpement.md](../../docs/developpement.md#tests-de-bout-en-bout-du-portail).

```bash
npm ci && npx playwright install chromium
E2E_DB_HOST=127.0.0.1 E2E_DB_PASSWORD=root npx playwright test
```

| Path | Content |
|---|---|
| `playwright.config.js` | Chromium, one worker, PHP built-in server started by Playwright |
| `fixtures.js` | Database reset, logged-in pages (`adminPage`, `userPage`), `api`, `tr()` (texts of `portal/i18n/*.json`, with the `t()` of `portal/i18n.js`) |
| `fake-smtp.js` | SMTP server simulated by a spec (test email of the Settings tab), messages kept in memory |
| `fake-push-relay.js` | Push service simulated by a spec, on port `E2E_PUSH_PORT` (`E2E_PORT` + 10), where the server sends its push notifications (`CARBURE_PUSH_URL`) |
| `server/router.php` | Router of `php -S`: portal, API, `/api/__e2e/reset` (APP_ENV=e2e only) |
| `server/seed.php` | Data set: users `admin` / `marie` (password: `<username>-password`), categories, budgets, rules, accounts |
| `specs/` | One file per part of the portal |
