# AGENTS.md

Rules for coding agents and contributors on Carbure. Pending work: [TODO.md](TODO.md).

## Project

Household budget/bank-transaction tracker: **portal** `portal/` (Vue 3 global build, no
bundler) + **PHP 8.2+ REST API** `src/` (no framework, no runtime dependency; Composer only
for dev tools: PHPStan, PHPUnit), also used by the
**iOS app**. Transactions synced via woob, categorized by keywords; APNs notifications;
read-only MCP server at `/api/mcp`. Production = Docker image (nginx + PHP-FPM + woob)
built by the GitHub release workflow; committing/pushing is not deploying.

## Layout

| Path | Content |
|---|---|
| `src/api.php` | HTTP entry (`/api/*`); without `conf/<env>.ini` serves only `/api/setup` |
| `src/autoload.php` | Loads `src/lib`, `src/resources`, then `Config::load(getenv('APP_ENV') ?: 'prod')` |
| `src/lib/` | `Config`, `Db` (mysqli), `Logger`, `Jwt`, `Webservice` (+ security headers), `ApiResolver`, `ApiRoute`, `Woob`, `Apns`, `Setup`/`Installer`, `Migrator`, `Setting` |
| `src/resources/` | `User`, `Bank`, `Transaction`, `Budget`, `Category`, `Insight`, `Device`, `ApiToken`, `Mcp`, `OAuth`, `System` |
| `portal/` | Mixins in `modules/`, API calls in `services/apiService.js`, i18n in `i18n.js` |
| `swagger/` | OpenAPI via reflection + Swagger UI |
| `sql/carbure.sql`, `sql/migrations/` | Full schema; dated migrations (`Migrator`, table `schema_migrations`) |
| `tools/migrate.php` | CLI migrations (run by the Docker entrypoint) |
| `docker/` | `Dockerfile`, `nginx.conf`, `docker-compose.yml`, `docker-compose.dev.yml` |
| `data/` | Untracked instance data: `conf/prod.ini`, `conf/certs/`, `logs/`, `cache/`, `woob/` |
| `conf/*.sample.ini`, `testing.ini`, `e2e.ini` | Sample/test config (`prod.ini` never tracked) |
| `tests/unit/`, `tests/integration/` | PHPUnit (integration needs MariaDB) |
| `tests/portal/` | Portal unit tests (`node:test`) |
| `tests/e2e/` | Playwright, real API, DB recreated per test; `server/seed.php` = also `start.sh` data |
| `docs/`, `mkdocs.yml` | MkDocs docs; `VERSION` = `dev` in repo |

## Commands

```bash
./start.sh   # dev stack http://localhost:8000/portal/ (admin/admin-password); --reset --build --stop --clean
find src tests swagger -name '*.php' -not -path '*/node_modules/*' -print0 | xargs -0 -n1 php -l
composer install && composer phpstan   # dev tools (PHPStan, PHPUnit) + PHP types
for f in $(find portal -name '*.js' -not -path '*/vendor/*'); do cp "$f" /tmp/c.mjs && node --check /tmp/c.mjs; done
composer test                          # PHPUnit unit suite (vendor/bin/phpunit --testsuite unit)
DB_HOST=127.0.0.1 DB_USER=root DB_PASSWORD=root composer test:all   # + integration (recreates carbure_test)
node --test 'tests/portal/*.test.mjs'
cd tests/e2e && npm ci && npx playwright install chromium               # once
E2E_DB_HOST=127.0.0.1 E2E_DB_PASSWORD=root PHP_BIN=… npx playwright test
git config core.hooksPath .githooks   # pre-commit lint, pre-push unit tests
```

## Local environment (WSL)

- Shell is zsh: don't name a variable `path`; `echo ===` fails. Prefer `bash -c '…'`.
- PHP not installed. Download a static CLI into a scratch dir:
  `https://dl.static-php.dev/static-php-cli/common/php-8.3.x-cli-linux-x86_64.tar.gz`
  (lint + unit tests; no `mysqli`/`pcov`) or `…/bulk/…` (has `mysqli`: integration/e2e
  with MariaDB in Docker; coverage only in CI).
- Composer not installed either: download `https://getcomposer.org/download/latest-stable/composer.phar`
  into the same scratch dir and run it with that PHP (`php composer.phar install`, `… test`).
- Case-insensitive FS; LF line endings (`.gitattributes`).

## API conventions

- Flow: `api.php` → `Webservice::exec()` → `ApiResolver::findRoute()` → static method in
  `src/resources/`. Route cache in APCu, auto-invalidated on file mtime changes.
- Route = **public static** method with
  `#[ApiRoute('/path', method: 'GET', public: false, stream: false, raw: false)]`.
  `public: true` = no JWT; `stream: true` = SSE (`Webservice::sendProgress()`/`sendHeartbeat()`).
- Request params (JSON body + query string) are injected **by name** into method params:
  renaming one breaks the API; unknown param → 400.
- Errors: `throw new Error(msg, 4xx)` (code = HTTP status); `Exception` = 500.
  Response: `{"error","code","uid"}`.
- SQL: prepared statements only (`Db::execute`, `Db::queryOne`).
- Current user: `Jwt::getUserIdFromToken()`; headers: `Webservice::getHeader()`.
- Admin actions: `User::requireAdmin()` first (`users.is_admin`); portal shows them only
  if `isAdmin`. Non-admins read/edit only their own profile.
- Passwords: `password_hash()`; legacy salted SHA-256 migrated at login (`User::verifyPassword`).
- `Logger` masks `password`/`token`; never log secrets otherwise.
- PHPDoc on every method; match existing style.
- **Types**: every parameter, return value and property has a native type (no
  `declare(strict_types=1)`: request params are coerced), arrays typed in the PHPDoc
  (`array<string, mixed>`, `list<…>`). PHPStan (Composer dev dependency, `phpstan.neon`,
  level 6 + rule `tools/phpstan/NativeTypesRule.php`) checks it in pre-commit and CI; no
  `@phpstan-ignore`, no baseline. Route params come as strings from the query string:
  never a bare `bool` (`"false"` would be `true`), keep what the iOS app sends valid.
- **Complexity**: cyclomatic complexity ≤ 10 per function/method (rule
  `tools/phpstan/CyclomaticComplexityRule.php`, threshold in `phpstan.neon`): split a
  growing method into small private ones, use lookup tables instead of if/else chains.

## Domain rules

- **Household model**: all users see the same data; budgets/categories have no user
  column; a transaction belongs to the first owner (`MIN(user_id)`) of the synced account.
- **Transaction UUID** = `md5(rdate . label . amount)` (card labels truncated to 24
  chars), computed after `Transaction::cleanLabel()` + config `regex_label`. Identical
  rows in one batch: `md5(uuid . '#n')` (`Transaction::prepareAll()`). Changing label
  cleaning creates duplicates → needs a migration.

## Compatibility & safety

- **Backward compatibility**: iOS app isn't updated with the server. Only add routes or
  optional params; never rename/remove or change an existing response format.
- **Schema**: change via `sql/migrations/YYYY-MM-DD_name.sql` (mandatory
  `-- applied-if: …` line, test-checked) **and** `sql/carbure.sql`. Applied at container
  start, by setup wizard, or portal admin banner. Code must not depend on an unapplied
  migration; flag it as a deployment action.
- **Public routes** limited to: `POST /user/login`, `GET /bank/sync` (JWT or
  `sync_token`), `GET|POST /mcp` (token or JWT), `GET /health`, MCP OAuth
  (`/.well-known/oauth-*`, `POST /oauth/register`, `GET /oauth/authorize`,
  `POST /oauth/token`). A unit test fails on any new public route.
- **Secrets**: nothing secret/personal/logs in the repo; samples use fake values. Never
  print or copy `data/conf/prod.ini`. Tests must not write to `data/logs/`.
- Security headers: API via PHP; portal via nginx (`docker/nginx.conf`, no `.htaccess`).
- **No external CDN**: every script, style, font and image is vendored and served locally
  (`portal/vendor`, `swagger/vendor`, docs: Material `privacy` plugin); the CSP of the
  portal and Swagger allows only `'self'` (+ `data:` images). `tests/portal/localAssets.test.mjs`
  checks it.

## Portal

- Every string via `t('key')`; add each key in **both** `fr` and `en`.
- Any added/changed feature gets a Playwright spec in `tests/e2e/specs/` (data: `seed.php`).
- Accessibility (WCAG 2.2 AA) is mandatory: text contrast ≥ 4.5:1 (icons 3:1) at rest,
  on hover and on focus — never lower it with `opacity` or a grey label on a colored
  background; a button class that sets its own background keeps it on hover.
  `specs/accessibility.spec.js` checks every tab: extend it when adding a screen or modal.
- No external CDN: a new library, font or image goes in `portal/vendor/` (listed in its
  `README.md` with version, source and license), never a `https://` URL in a page.

## Issues, branches, PRs and commits

- Every change starts from an issue (assigned, in the next milestone); the PR says `Closes #<n>`.
- Never push to `main`: branch from `origin/main` as `<type>/<issue>-<short-topic>`
  (e.g. `fix/26-codeql-alerts`).
- PRs are squash-merged and the **PR title becomes the commit of `main`**: it follows
  [Conventional Commits](https://www.conventionalcommits.org/), checked by CI:
  `type(scope): description` — English, imperative, lowercase start, no final period.
  - types: `feat`, `fix`, `security`, `perf`, `refactor`, `docs`, `test`, `ci`, `chore`
    (`build`, `revert` also accepted);
  - optional scopes: `api`, `portal`, `sync`, `import`, `mcp`, `oauth`, `docker`, `db`, `deps`…;
  - breaking change: `type(scope)!: …` (exceptional: the iOS app must keep working).
- Commit messages inside a branch: same format; body = bullet list of changes.
- Label the PR with its kind (`enhancement`, `bug`, `security`, `documentation`,
  `dependencies`): `.github/release.yml` groups the release notes by label.

## Definition of done

- PHP/JS lint clean, PHPStan clean; unit tests green, new code tested.
- Portal change: e2e spec added, e2e suite green.
- PHP coverage ≥ 90 % in CI (unit + integration).
- SQL migration if schema changed, flagged as deployment action.
- `TODO.md` updated (check done, add discovered debt/manual steps).
- `README.md` / `docs/` updated if behavior or API changes.
