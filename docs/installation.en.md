---
title: "Installing with Docker: self-hosted family budget"
description: "Install Carbure with Docker: nginx, PHP-FPM and woob image, MariaDB database, in-browser setup wizard and automatic database updates."
icon: material/download-outline
---

# Installation

Carbure is installed **entirely from the browser**: on first launch, the portal
shows a wizard that checks the database, creates (or updates) its tables and the
administrator account. No command is needed.

- **Docker** (recommended): everything is included (nginx, PHP, woob) and the database is
  prepared by `docker/docker-compose.yml`.
- **Synology NAS**: see the [step-by-step tutorial](synology.md).
- **Existing web server** (PHP + MariaDB/MySQL), without Docker.

Before installing, discover Carbure's [features](fonctionnalites.md) and its
[comparison](comparatif.md) with other self-hosted budget managers.

## Trying Carbure (demo) { #essayer-carbure-demo }

The simplest way: the **online demo** at [demo.carbure.org](https://demo.carbure.org/),
with the `demo` / `carbure` account and fictitious data.

To run it at home, with a single command, no database to prepare and no installation:

```bash
docker run --rm -p 8080:80 ltoinel/carbure:demo
```

Open `http://localhost:8080/portal/` and sign in with `demo` / `carbure`
(user), shown again on the login screen. The `admin` administrator account gets a
random password, new on every start, displayed in the container output
(`docker logs <container>` if it runs in the background):

```text
 User:          demo / carbure
 Administrator: admin / 3f9c1a7e5b2d8046   (new password at each start)
```

A fictitious household has been living there for a year: two salaries, about twenty categories with their budgets and rules,
seasonal expenses, savings.

The demo image is the usual image with MariaDB and the demo data
inside: on every start, the database is recreated, the configuration is written with
random secrets and the data is reloaded. `Ctrl+C` (or `docker stop`) stops it.

!!! warning "For trying only"
    **All data is erased on every start** and the `demo` account password is public:
    do not enter any real data in it, and only publish it on the Internet with the precautions
    below. Bank
    sync and notifications are not configured. For your own budget, install
    Carbure with the `ltoinel/carbure` image (below). For safety, the demo image
    refuses to start on a `/data` volume from a real installation.

The demo users are in French; `-e LANGUAGE=en` (or `es`, `it`,
`de`, `pt`) picks another language. Each version also has its own demo image:
`ltoinel/carbure:<version>-demo` (for example `1.6.0-demo`).

### Publishing a demo { #publier-une-demo }

For an online demo, `docker/demo/docker-compose.yml` runs the hardened image: read-only
file system (everything written goes to memory, erased on every start),
no privilege escalation, only the capabilities needed by MariaDB, PHP-FPM and nginx,
port published on `127.0.0.1` only:

```bash
docker compose -f docker/demo/docker-compose.yml up -d
docker compose -f docker/demo/docker-compose.yml logs carbure | grep Administrator
```

`CARBURE_DEMO_PORT` (8080 by default) and `CARBURE_DEMO_LANGUAGE` (`fr` by default) are set
in the environment or in a `.env` file next to it. Put an HTTPS reverse proxy in front
that forwards everything to the container, without rewriting its security headers (they depend on the
path: portal, Swagger, API), and without buffering for the API (live progress):

```nginx
location / {
    proxy_pass http://127.0.0.1:8080;
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto https;
}
location /api/ {
    proxy_pass http://127.0.0.1:8080;
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto https;
    proxy_buffering off;
    proxy_read_timeout 3600s;
}
```

Visitors can change the data: a regular restart resets it and
changes the `admin` password. The `docker/demo/run.sh` script (to be copied next to the compose
file on the server) starts the demo, updates it when a new image is published
(the container is only recreated if the image has changed), with `--reset` recreates it with fresh
data, and displays the `admin` password. For example in the crontab:

```cron
0 * * * * /srv/carbure-demo/run.sh >> /var/log/carbure-demo.log 2>&1
0 4 * * * /srv/carbure-demo/run.sh --reset >> /var/log/carbure-demo.log 2>&1
```

The log contains the `admin` password: keep it readable by you only.

## With Docker (recommended) { #avec-docker-recommande }

```bash
git clone https://github.com/ltoinel/Carbure.git && cd Carbure/docker
docker compose up -d
```

Open `http://<host>:8080/`: the setup wizard appears, already filled in with the
database from `docker/docker-compose.yml`. Click **Continue**, choose the administrator
password, then **Install**. That's it.

On a new database, the wizard also offers, to get you started, common **categories**
with their categorization **rules** (stores, energy suppliers, telecom operators…) and
**insights** (spending, income, rest of the month, savings, budget overrun…). These options
are checked by default; everything can still be changed afterwards. Their content is in
`sql/starter.json`.

Then add your banks from the portal's **Accounts** tab (**+** button):
choose the bank, enter the requested credentials, then the accounts to track. The
credentials are handed to woob, which keeps them on your server; Carbure does not
store them.

The instance settings (log level and retention, woob, mobile push notifications, email SMTP
server…) can then be viewed and changed in the portal, under **Administration →
Settings**: see [Configuration](configuration.md).

To choose the database password yourself, create a `docker/.env` file next to
`docker/docker-compose.yml` **before** the first start:

```bash
DB_PASSWORD=un-mot-de-passe-solide
```

| Variable | Purpose | Default |
|---|---|---|
| `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD` | Database offered by the wizard (the password does not need to be re-entered) | `db`, `3306`, `carbure`, `carbure` |
| `LANGUAGE` | Language offered for the administrator (`fr`, `en`, `es`, `it`, `de`, `pt`) | `fr` |
| `SYNC_INTERVAL` | Automatic sync of all accounts every N seconds (`86400` = once a day) | disabled |
| `CARBURE_WOOB_PATH` | woob command written into the configuration by the wizard; the image already sets it (`env HOME=/data/woob woob`) | `woob` |

The project's `data/` folder, mounted on `/data` in the container, holds everything that is
specific to your instance: the configuration written by the wizard (`data/conf/prod.ini`, with
random secrets), the logs (`data/logs`) and the
woob configuration with your banks (`data/woob`). It is the only folder to back up, along with
the database. No administrator password is passed through
the environment: it is chosen in the wizard.

The image contains nginx (portal, Swagger and API on port 80), PHP-FPM and woob. Its
`HEALTHCHECK` queries `GET /api/health` every 5 minutes (every 10 seconds
during the first 2 minutes, with Docker 25 or later): `docker ps` shows `healthy`
when the database responds.

!!! note "Access from the Internet"
    From your local network, the wizard is directly accessible. If Carbure is
    reached from a public address before being installed, the wizard asks for an
    **installation code**, displayed in the container logs
    (`docker compose logs carbure`) and written to `/data/conf/setup.code`.

    Behind a reverse proxy (NAS proxy, Traefik, Caddy…), requests seem to
    come from the proxy, hence from the local network: **install Carbure before exposing it on
    the Internet**, or enforce the code by creating `/data/conf/setup.code` beforehand,
    containing a code of your choice.

### Getting the image { #recuperer-limage }

The image is published on [Docker Hub](https://hub.docker.com/r/ltoinel/carbure) with every release, for `amd64` and `arm64`
(Intel as well as ARM Synology NAS):

```bash
docker pull ltoinel/carbure:latest
```

| Tag | Contents |
|---|---|
| `latest` | Latest release |
| `1.2.0` | A specific version (recommended in production, to control updates) |
| `1.2` | Latest patch version of 1.2 |

Without `docker compose`, with an existing MariaDB/MySQL database (the database and its user
must already exist; the wizard asks for them on first launch):

```bash
docker run -d --name carbure --restart unless-stopped -p 8080:80 \
  -e SYNC_INTERVAL=86400 -v carbure-data:/data \
  ltoinel/carbure:latest
```

### Updating { #mettre-a-jour }

```bash
docker compose pull && docker compose up -d
```

The `/data` volume is kept. On every start, the container updates the database
automatically (the new version's migrations are applied) and stops
with an explicit message if one of them fails. Remember to back up the database before an
update.

When `docker compose pull` brings a new major version of MariaDB (for example from
11 to 13), the `db` container upgrades the existing database when it starts
(`MARIADB_AUTO_UPGRADE`). There is no going back: the database can no longer be opened by
the old version, hence the importance of the backup. To stay on a given version,
pin it in `docker/docker-compose.yml` (`image: mariadb:11`).

### Upgrading to 1.8.0 { #passer-a-la-180 }

- The migration `2026-10-26_user_browser` is applied at startup: it records the web
  browsers of the portal logins, listed in **My profile → My devices** (the code works
  before it, with no browser listed).
- The `push_url` setting is gone: the notification service is always Carbure Push
  (`https://api.carbure.org`), a value left in `/data/conf/prod.ini` is ignored. The
  registration of the instance, its key and the state of the service are in
  **Administration → Mobile push**.
- The QR code of **My devices** gives the address of the API to the mobile apps: set
  `public_url` (**Administration → Settings**) if the portal is also opened through a
  local address.

### Upgrading to 1.6.0 { #passer-a-la-160 }

The `2026-10-25_device_model` migration is applied at startup: model, system version
and app version of each mobile device (unknown for existing devices
until their next connection). It also fixes the platform of Android devices
already registered, recorded as iOS until then: their push notifications start working again.

### Upgrading to 1.5.0 { #passer-a-la-150 }

To be done **in this order**:

1. **Back up the database**, as before any update.
2. **Push notifications**: 1.5.0 no longer sends notifications to Apple
   (APNs) itself but goes through the Carbure Push service. As long as the instance is not
   registered with it, **no push notification is sent** (everything else works normally).
   In 1.5.0, the default address of the service (`https://push.carbure.org`) is not the
   right one: first set `push_url` = `https://api.carbure.org` (the default address
   starting with 1.6.0; starting with 1.8.0, this setting is gone: the service is always
   `https://api.carbure.org`). Once the service is available, register the instance right after the update (see
   [Push notifications](#notifications-push-carbure-push)), then remove the
   `[apns]` section and the `.p8` key from `/data/conf`.
3. **Update** the image (`docker compose pull && docker compose up -d`). Three
   migrations are applied at startup:
    - `2026-10-23_device_platform`: device platform (`ios` for existing ones);
    - `2026-10-23_user_budget_alert_category`: budget watched by budget alerts
      (all budgets for existing users);
    - `2026-10-24_user_mfa`: two-factor authentication (empty tables).
4. **Check**:
    - **Administration → API** shows Swagger UI: if a message says the frame is
      blocked, the DSM reverse proxy adds its own `X-Frame-Options: DENY` header
      (to be removed for `/swagger/`);
    - **My profile → Notifications**: the large expense threshold is now there,
      along with the watched budget and its threshold.

New features to enable if needed:

- **Two-factor authentication** (disabled by default): before enforcing it
  (**Administration → Settings**, `mfa_required`), set it up on your own account
  (**My profile**) and keep your recovery codes. The current iOS app keeps
  signing in from an already known iPhone, but a **new** iPhone is rejected until the app
  can enter the code. If you lose the app and the codes:
  `mfa_required=false` in `/data/conf/prod.ini`.
- **Backup** (**Administration → Backup**): export your data to a ZIP;
  importing **replaces** the data of each imported type.
- **Languages**: Spanish, Italian, German and Portuguese. On an instance that is not
  in French, set the savings category for **Trends** in
  **Administration → Settings** (`savings_category`, "Epargne" by default).

Nothing changes for the label cleanup rules: the sample configuration no longer
contains `regex_label["/\s(CARTE.*|CARTE|CART|CAR|CA|C)$/"]=""`, but keep it in
your `prod.ini` if it is there (removing it would create duplicate card
transactions).

### Push notifications (Carbure Push) { #notifications-push-carbure-push }

Notifications for the iOS and Android apps go through the external Carbure Push service:
in **Administration → Mobile push**, check that the relay is operational,
click **Register the instance**, read and accept the consent (device tokens and
notification content go through this relay, which forwards them to Apple
and Google; the API key obtained is saved in `/data/conf/prod.ini`), then
**Send a test notification** (the key status changes to "Valid"). Without a key, no
push notification is sent. See [Configuration](configuration.md#push).

!!! warning "Updating from a version that sent notifications itself (APNs)"
    The `[apns]` section and the `.p8` key are no longer used. **Register the instance
    (or set `push_api_key`) before or right after the update**, otherwise
    push notifications stop. The `2026-10-23_device_platform` migration (device
    platform) is applied when the container starts.

### Email notifications (SMTP) { #notifications-par-email-smtp }

To also send notifications by email, enter the SMTP server (host, port,
encryption, credentials, sender) in **Administration → Settings**, section
**Emails (SMTP)**, then check it with **Send a test email** (see
[Configuration](configuration.md#smtp)). Each user then chooses, in **My
profile → Notifications**, which notifications they receive by email; their profile must have
an email address.

### Maintenance { #maintenance }

```bash
docker compose logs -f carbure                                         # nginx, PHP and startup logs
docker compose exec carbure ls /data/logs                              # Carbure logs
docker compose exec -u www-data carbure php tools/migrate.php --status # database schema version
```

!!! note "woob modules"
    woob downloads its modules (including your bank's) into `/data/woob` and updates them
    automatically. A module fix not yet published (for example for
    BNP) can be copied into `/data/woob/.local/share/woob/modules/3.7/woob_modules/`;
    `curl_cffi`, required by the recent BNP module, is already installed in the image.

## On an existing web server { #sur-un-serveur-web-existant }

### Prerequisites { #prerequis }

- PHP **8.2** or later with `mysqli`, `curl`, `openssl` and `json`; APCu recommended (route
  cache).
- MariaDB ≥ 10.3 or MySQL 8, with a database and a user for Carbure.
- A web server able to forward `/api/` to PHP-FPM, for example nginx (example
  below). Carbure does not ship an `.htaccess` file: protecting sensitive
  folders must be configured in the server.
- [woob](https://woob.tech/) installed for the web server user. The recent BNP module
  requires `curl_cffi` (`pip install "curl_cffi>=0.7"`).
- For push notifications: outbound HTTPS access to the Carbure Push service
  (`https://api.carbure.org`; no Apple or Firebase key is needed). Optional: an SMTP server for
  email notifications.

### 1. Copy Carbure { #1-copier-carbure }

Copy a release archive (`carbure-vX.Y.Z.tar.gz`: `src`, `portal`, `swagger`,
`sql`, `tools/migrate.php`, `conf/prod.sample.ini` and the
`VERSION` file shown in the portal footer) or the repository contents into the site
folder, for example `/var/www/carbure`. The `conf/` folder must be **writable by the web server**:
the wizard writes the configuration there.

### 2. Configure the web server { #2-configurer-le-serveur-web }

- `/api/…` URLs are sent to `src/api.php` (the `/api` prefix is stripped by the API).
- `portal/` is served as static files (for example under `/portal/`).
- `swagger/` can be served to browse the OpenAPI specification.
- `data/`, `conf/`, `sql/`, `tests/` and `tools/` must **not** be exposed.
- Use HTTPS (also required to [install the portal as an app](portail.md#installer-le-portail-comme-une-application)).
  Serve `.webmanifest` with the `application/manifest+json` type (see `docker/nginx.conf`).

nginx example (the Docker image's one is in `docker/nginx.conf`):

```nginx
location = / { return 302 /portal/; }
location /portal/ { root /var/www/carbure; }
location /api/ {
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME /var/www/carbure/src/api.php;
    fastcgi_pass unix:/run/php/php-fpm.sock;
    fastcgi_read_timeout 3600;   # sync can take a long time
    fastcgi_buffering off;       # SSE stream
}
location ~ ^/(data|conf|sql|tests|tools|docker)/ { deny all; }
```

### 3. Run the wizard { #3-lancer-lassistant }

Open the portal (`https://<your-server>/portal/`): the wizard asks for the
database, creates its tables (or updates an existing Carbure database, after confirming
a backup), the administrator account and, on a new database, the optional starter pack
(categories, rules, insights), then writes `data/conf/prod.ini` with
random secrets (`jwtsecret`, `sync_token`…). The woob command there is `woob`: adjust
`woob_path` if woob is installed elsewhere (see [Configuration](configuration.md#woob)).

From a public address, the wizard asks for the installation code written to
`data/conf/setup.code` (and to the PHP error log). See
[Security](securite.md#assistant-dinstallation).

### 4. Add a bank { #4-ajouter-une-banque }

From the portal's **Accounts** tab (**+** button): choose the bank among those
supported by woob, enter the requested credentials (handed to woob, which keeps them;
Carbure does not store them), then the accounts to track. woob must be installed for
the web server user.

### 5. Schedule the sync { #5-planifier-la-synchronisation }

The `sync_token` key in `data/conf/prod.ini` lets you trigger the sync without an account.
The portal's **Administration → Sync** tab gives the full command to copy
(and creates the token if it is missing):

```bash
# crontab: every day at 7 a.m.
0 7 * * * curl -sN -H "X-Sync-Token: <sync_token>" https://exemple.fr/api/bank/sync > /dev/null
# or with wget
0 7 * * * wget -qO /dev/null --header="X-Sync-Token: <sync_token>" https://exemple.fr/api/bank/sync
```

!!! warning "Migration: the token in the URL is deprecated"
    A scheduled task that still calls `https://exemple.fr/api/bank/sync?token=<sync_token>`
    still works, but passing the token in the URL is **deprecated** (it ends up in
    proxy logs and shell history): a warning is logged on every
    call. Remove `?token=…` from the URL and pass the token in the `X-Sync-Token` header,
    as above.

### Updating { #mettre-a-jour_1 }

Replace the Carbure files while keeping the `data/` folder (configuration, logs, banks). If the new version
changes the database, a **database update** banner is shown to
the administrator in the portal: back up the database, then click **Update**.
Migrations already applied by hand (phpMyAdmin) are recognized.

From the command line, `php tools/migrate.php --status` shows the schema version and
`php tools/migrate.php` applies the migrations.

After a deployment that changes the routes, the APCu cache is invalidated automatically
(signature of the files in `src/resources/`, cache key version).

## If the sync fails { #en-cas-dechec-de-la-synchronisation }

In the **Accounts** tab, each failed step shows the cause returned by woob, including
when woob returns no account or transaction but reports an error (for example
`Bug(bnp): 403 Client Error: Forbidden`: the bank's website rejects woob): the account is
then in error, not "0 new transactions". Bank
websites change regularly: a woob module can stop working overnight.
First of all, check whether the issue is known or being fixed in
the woob issues, by searching for your bank's module name (the portal offers
this link directly):

```
https://gitlab.com/search?group_id=11540390&project_id=25520182&scope=work_items&search=<module>&sort=created_desc
```

Replace `<module>` with the name of the woob module (for example `bnp`, `creditmutuel`,
`boursorama`). Once the fix is published, update the bank modules from
the **Accounts** tab (**Update the bank modules** button, administrator; the
first time, it asks you to approve the woob repository key, see [Security](securite.md)),
or restart the Docker container: once the repository is approved, `woob config update` is
run on every start and updates all bank modules. The
`woob_auto_update=true` option is not enough: woob only offers a new version of a module
after that module crashes, and Carbure cannot answer its prompt.

## Need help? { #besoin-daide }

A question about installation, configuration or usage? Check the [FAQ](faq.md)
first, then ask it on the [Discord server](https://discord.gg/QsxYw4Rx9D)
(see [Support](assistance.md)). Keep [issues](https://github.com/ltoinel/Carbure/issues) for confirmed
bugs, and report a security vulnerability privately (see [Security](securite.md)).
