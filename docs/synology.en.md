---
title: "Tutorial: install Carbure on a Synology NAS"
description: "Install Carbure on a Synology NAS (DSM 7.2) with Container Manager and MariaDB 10, without the command line, then sync your bank accounts every day."
icon: material/nas
---

# Tutorial: Carbure on a Synology NAS

This tutorial installs Carbure on a Synology NAS (DSM 7.2 or later) with **Container
Manager**, using the NAS's **MariaDB 10** database. Everything is done from the DSM
interface and the browser, without a single command. Allow about fifteen minutes.

!!! info "What you need"
    - A NAS compatible with Container Manager (most "+" and recent models, with an
      Intel/AMD or 64-bit ARM processor).
    - A DSM administrator account.
    - The IP address of your NAS on the local network (for example `192.168.1.10`), shown
      in **Control Panel → Network → Network Interface**.

## 1. Install the packages { #1-installer-les-paquets }

In **Package Center**, install:

- **MariaDB 10**: the database;
- **Container Manager**: to run Carbure;
- **phpMyAdmin**: to create the Carbure database (it also installs Web Station).

## 2. Prepare MariaDB 10 { #2-preparer-mariadb-10 }

Open the **MariaDB 10** application:

1. Note the **port** shown (**3307** by default on Synology).
2. Check **Enable TCP/IP connection**, then **Apply**.
3. If not already done, set the password of the MariaDB `root` user
   (**Change MariaDB password** button).

## 3. Create the Carbure database and user { #3-creer-la-base-et-lutilisateur-de-carbure }

Open **phpMyAdmin** (from the DSM main menu), log in with `root` and the MariaDB
password, then:

1. **User accounts** tab → **Add user account**.
2. **User name**: `carbure`.
3. **Host name**: choose **Use text field** and enter `172.17.%`:
   Carbure connects from its container, on the NAS's Docker network (`bridge`), and
   this account cannot be used from another machine. If the wizard cannot connect
   (step 6), check the subnet in **Container Manager** → **Network** → `bridge`, or use
   **Any host** (`%`).
4. **Password**: choose a strong one and **write it down**.
5. Check **Create database with same name and grant all privileges**.
6. Click **Go** at the bottom of the page.

You now have a `carbure` database and a `carbure` user. Carbure will create its tables
itself in step 6.

## 4. Download the Carbure image { #4-telecharger-limage-carbure }

The image is published on [Docker Hub](https://hub.docker.com/r/ltoinel/carbure): you can
find it directly from DSM.

1. Open **Container Manager** → **Registry**.
2. Search for `carbure` and select **ltoinel/carbure**.
3. Click **Download**, choose the `latest` tag (or a version, for example `1.0.0`), then
   **Apply**. The image appears in **Image** once downloaded.

## 5. Start the Carbure container { #5-lancer-le-conteneur-carbure }

1. With **File Station**, create a `carbure` folder in the `docker` shared folder
   (created by Container Manager), then a `data` subfolder: `docker/carbure/data`.
2. Open **Container Manager** → **Image**, select **ltoinel/carbure**, then **Run**.
3. **General Settings**: **Container Name** `carbure`; check **Enable auto-restart**.
   Click **Next**.
4. **Advanced Settings**:
    - **Port Settings**: **Local Port** `8080`, **Container Port** `80` (TCP);
    - **Volume Settings** → **Add Folder**: `docker/carbure/data`, mounted on `/data`
      (Carbure configuration, logs and banks);
    - **Environment** → add `SYNC_INTERVAL` with the value `86400` (bank sync once a
      day);
    - leave **Execute container using high privilege** unchecked: Carbure does not need
      it.
5. Click **Next**, then **Done**: Container Manager starts Carbure.

The database settings do not go into the container: you enter them in the setup wizard
(step 6), which saves them in `docker/carbure/data`.

!!! tip "Port already in use?"
    If port `8080` is already taken on your NAS, choose another **Local Port**, for
    example `8095`, and use that port from then on.

## 6. Install Carbure from the browser { #6-installer-carbure-depuis-le-navigateur }

Open `http://192.168.1.10:8080/` (the address of your NAS): the **setup wizard** is
displayed.

1. **Database**: enter **the NAS IP address** (for example `192.168.1.10`, not
   `localhost`: from the container, `localhost` refers to the container itself), the
   MariaDB 10 **port** (step 2, `3307` by default), the `carbure` database and user and
   the **password** from step 3, then **Continue**.
2. **Installation**: Carbure says it is going to create its tables. Choose the username
   and password of the **administrator account** (at least 8 characters). Leave the
   **Getting started** options checked to get ready-to-use categories, rules and insights
   (editable later), then **Install**.
3. **Done**: log in with this account.

If the wizard shows a database connection error, check the IP address, the port, the
**Enable TCP/IP connection** option of MariaDB 10 and the host (`172.17.%`) of the
`carbure` user, then correct your input and try again.

Carbure settings (log level and retention, woob, mobile push notifications, SMTP server
for emails…) can then be viewed and changed in the portal, under the **Administration →
Settings** tab, without opening any file on the NAS: see [Configuration](configuration.md).

## 7. Add your bank accounts { #7-ajouter-vos-comptes-bancaires }

In the portal, **Accounts** tab → **+**:

1. Choose your bank from the list of banks supported by [woob](https://woob.tech/) (for
   example BNP Paribas, Crédit Mutuel, Boursorama).
2. Enter the requested credentials and click **Connect the bank**: they are handed over
   to woob, which keeps them in the `docker/carbure/data` folder of the NAS; Carbure does
   not store them.
3. Click **Follow** for each account to sync, then **Save**.

!!! note "\"Woob will not start as long as config file … is readable by group or other users\""
    woob refuses a credentials file readable by other users, which happens when it is
    copied into a NAS shared folder. Carbure fixes these permissions when the container
    starts and before each call to woob. If the message persists (shared folder ACLs),
    open **Container Manager** → **Container** → `carbure` → **Action** → **Open
    terminal** → **Create** → `sh`, then:
    `chmod 600 /data/woob/.config/woob/backends`

Sync runs account by account with the sync button, and automatically every day thanks to
`SYNC_INTERVAL`.

!!! tip "Schedule the sync with the DSM Task Scheduler"
    To choose the sync time rather than an interval, leave `SYNC_INTERVAL` empty and
    create a task in **Control Panel → Task Scheduler → Create → Scheduled Task →
    User-defined script** (user `root`, schedule: every day at 7 a.m.). In **Task
    Settings → Run command**, paste the `curl` command from the **Administration → Sync**
    tab of the portal:

    ```bash
    curl -fsS -H "X-Sync-Token: <sync_token>" http://localhost:8080/api/bank/sync > /dev/null
    ```

!!! warning "Migration: the token in the URL is deprecated"
    A scheduled task created with the old form
    `curl "http://localhost:8080/api/bank/sync?token=<sync_token>"` (or `wget` with the
    same URL) still works, but it is **deprecated**: the token passed in the URL ends up
    in the logs and a warning is logged on every call. In **Task Scheduler**, edit the
    task (**Edit → Task Settings**): remove `?token=…` from the URL and add
    `-H "X-Sync-Token: <sync_token>"` (with `wget`:
    `--header="X-Sync-Token: <sync_token>"`), as above.

## 8. (Optional) HTTPS access with a domain name { #8-facultatif-un-acces-https-avec-un-nom-de-domaine }

To use the iOS app or reach Carbure from outside, go through the DSM reverse proxy:
**Control Panel → Login Portal → Advanced → Reverse Proxy → Create**:

- **Source**: protocol `HTTPS`, hostname `carbure.your-domain.com`, port `443`;
- **Destination**: protocol `HTTP`, hostname `localhost`, port `8080`.

The certificate is created in **Control Panel → Security → Certificate** (Let's Encrypt).
The sync is a continuous stream: if it stops after one minute behind the proxy, increase
the timeouts in the advanced settings of the rule.

!!! warning "Exposure to the Internet"
    Install Carbure (step 6) **before** opening it to the Internet. Behind the DSM reverse
    proxy, requests appear to come from the NAS itself: the setup wizard would then not
    ask for a setup code. Once Carbure is installed, the wizard disappears.

## Secure the installation { #securiser-linstallation }

Carbure holds your accounts and, in `docker/carbure/data`, the bank credentials handed
over to woob and the secrets of its configuration. These DSM settings protect them:

- **Firewall** (**Control Panel → Security → Firewall** → **Enable firewall** → **Edit
  Rules**): allow ports `8080` (Carbure) and `3307` (MariaDB 10) only from your local
  network (for example `192.168.1.0/24`) and deny them from everywhere else. On your
  router, forward only port `443` (step 8), never `8080` or `3307`.
- **`docker` shared folder** (**Control Panel → Shared Folder** → `docker` → **Edit** →
  **Permissions**): access restricted to administrators; do not share it with the other
  members of the household, over SMB or otherwise.
- **HTTPS**: on the reverse proxy rule from step 8, enable **HSTS** (the browser will then
  refuse to fall back to HTTP), with a Let's Encrypt certificate.
- **Encrypted backups**: in the **Hyper Backup** task that contains `docker/carbure/data`
  and MariaDB 10, enable **client-side encryption**, especially if the destination is in
  the cloud.
- **DSM account**: enable **2-factor authentication** and **Auto Block** (**Control
  Panel → Security → Protection**): the NAS hosts everything else.
- **Updates**: apply the updates of Carbure (below), DSM and MariaDB 10. Every Carbure
  image is scanned (known vulnerabilities) before being published.

See also the [Security](securite.md) page.

## Update Carbure { #mettre-a-jour-carbure }

In **Container Manager** → **Image**, the **Update available** badge appears on
`ltoinel/carbure` when a new version is published: click **Update** (or download the
`latest` tag again from **Registry**). Then, in **Container** → `carbure` → **Action**:
**Stop**, **Reset** (the container is recreated with the new image, keeping its ports, its
`data` folder and its variables), then **Start**.
On restart, Carbure updates its database automatically.

Remember to back up the database before an update: phpMyAdmin → `carbure` database →
**Export** → **Go**, or a **Hyper Backup** task that includes MariaDB 10.

## Back up { #sauvegarder }

- **Database**: phpMyAdmin export or Hyper Backup (**MariaDB 10** application).
- **Configuration and banks**: the `docker/carbure/data` folder (Hyper Backup or a copy).
