---
title: "Frequently asked questions"
description: "Carbure FAQ: price, supported banks, where the data lives, requirements, mobile apps, notifications, household, import, backup, security, AI agents and updates."
icon: material/frequently-asked-questions
---

# Frequently asked questions

A question that isn't here? Ask it on the
[Discord server](https://discord.gg/QsxYw4Rx9D) (see [Support](assistance.md)).

## Discover Carbure { #decouvrir-carbure }

??? question "Is Carbure free?"
    Yes. Carbure is **free and open source** (MIT license): the code is on
    [GitHub](https://github.com/ltoinel/Carbure). There is no subscription, no ads and no
    paid version. You host it yourself: the only cost is the machine that runs it (a NAS,
    a mini PC, a server).

??? question "Can I try it without installing anything?"
    Yes, on the **online demo** [demo.carbure.org](https://demo.carbure.org/) with the
    `demo` / `carbure` account and sample data, or at home with a single command:
    `docker run --rm -p 8080:80 ltoinel/carbure:demo` (see
    [Installation](installation.md#essayer-carbure-demo)).

??? question "How is it different from other budgeting apps?"
    Your data stays **with you**: Carbure goes through no commercial bank aggregator, and
    the publisher has no access to your accounts or your transactions. The
    [Comparison](comparatif.md) positions it against banking apps, aggregators and other
    self-hosted tools.

## Banks and synchronization { #banques-et-synchronisation }

??? question "Which banks are supported?"
    The **French banks** supported by [woob](https://woob.tech/), the open source project
    Carbure uses to fetch transactions. A bank is missing or a module is broken?
    Statements downloaded from the bank's website (OFX/QFX, QIF, CSV, CAMT.053) can be
    imported in the portal (see [Web portal](portail.md)).

??? question "My bank asks for approval on my phone, what should I do?"
    Start the synchronization from the portal: the approval request (strong customer
    authentication) is shown there, and the synchronization waits until you approve it in
    your bank's app or enter the code you received. Scheduled synchronization never
    triggers it. When a bank is connected, the list of its accounts waits for the approval.

??? question "I connected my bank with woob in the container's terminal, the portal does not see it"
    In the Docker image, Carbure runs woob as `www-data` with its configuration in
    `/data/woob`. A plain `docker exec` runs as root with another configuration, which
    Carbure does not see. Run woob as Carbure does:
    `docker compose exec -u www-data carbure env HOME=/data/woob woob bank list`. The
    simplest is still to connect the bank from the portal, which handles the strong
    authentication.

??? question "The synchronization fails, what should I do?"
    Check the synchronization log and the **Logs** page of the administration, then update
    the woob bank modules from the portal: a bank that changes its website often breaks
    its module. See
    [If the synchronization fails](installation.md#en-cas-dechec-de-la-synchronisation).

## Installation { #installation }

??? question "What do I need to install it?"
    The simplest: a machine with **Docker** (server, mini PC, NAS) and a **MariaDB or
    MySQL** database. The image contains nginx, PHP and woob; a wizard in the browser
    creates the schema and the administrator account. Without Docker: PHP 8.2 or later,
    MariaDB ≥ 10.3 or MySQL 8, a web server and woob. See [Installation](installation.md).

??? question "Does it run on a Synology NAS?"
    Yes, with Container Manager and MariaDB 10: the [Synology NAS tutorial](synology.md)
    walks through every step, up to HTTPS access.

??? question "How do I update Carbure?"
    Pull the new Docker image and restart the container: database updates are applied at
    startup, or in one click from the banner shown to administrators. Read the release
    notes before a major update (see [Installation](installation.md#mettre-a-jour)).

## Mobile apps and notifications { #applications-mobiles-et-notifications }

??? question "Is there a mobile app?"
    Yes, native **iPhone and Android** apps (transactions, checking, budgets,
    overviews, trends, synchronization, Face ID or fingerprint). Until they are published
    on the App Store and Google Play, the portal can be installed as an app on the phone.
    See [Mobile apps](applications-mobiles.md).

??? question "How do I connect the app to my server?"
    In the portal, **My profile → My devices** shows a QR code: scan it with the phone's
    camera, and the app opens with your server address already filled in. You can also
    enter the API address on the login screen. Your server must be reachable from the
    phone (preferably over HTTPS).

??? question "Why do push notifications go through Carbure Push?"
    Apple and Google only accept an app's notifications from its publisher's credentials:
    the **Carbure Push** relay (`api.carbure.org`) holds them, so you have no Apple key or
    Firebase account to create. It forwards the title and text of notifications without
    storing them, and knows neither your name nor your email address (see
    [Privacy](confidentialite.md)). Email notifications (SMTP), on the other hand, only go
    through your server.

## Data and security { #donnees-et-securite }

??? question "Where is my data stored?"
    In **your** database, on your machine. Bank credentials are kept by woob on your
    server. Nothing is sent to the publisher; only push notifications, if the
    administrator enables them, go through Carbure Push.

??? question "How do I secure my installation?"
    Serve Carbure over **HTTPS**, enable **two-factor authentication** (authenticator app,
    backup codes), and follow the recommendations of the [Security](securite.md) page.
    Accounts are locked after 5 failed login attempts.

??? question "How do I back up my data?"
    Back up the database regularly (and the container's `data/` folder). The
    **Administration → Backup** menu also exports the selected data (categories, rules,
    budgets…) to a file you can import again. See
    [Web portal](portail.md#sauvegarde-menu-administration).

??? question "Can several people in the household use it?"
    Yes: each member has their own account (username, password, language, theme,
    notifications, devices), with a **user** or **administrator** profile. The tracked
    bank accounts are shared by the household.

## AI agents { #agents-ia }

??? question "Can I query my budget with Claude or ChatGPT?"
    Yes, thanks to the built-in, read-only **MCP server**, which an administrator enables.
    Claude connects through OAuth, with no token to copy; other agents (ChatGPT, Cursor,
    Copilot, Gemini…) with an access token. See [AI agents (MCP)](mcp.md).

## Help { #aide }

??? question "Where can I get help or report a bug?"
    On the [Discord server](https://discord.gg/QsxYw4Rx9D); report a bug by
    opening an [issue](https://github.com/ltoinel/Carbure/issues/new/choose), and a
    vulnerability privately. See [Support](assistance.md).
