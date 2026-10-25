---
title: "Comparison: Carbure, Firefly III, Actual Budget, Kresus, Sure and ezBookkeeping"
description: "Carbure compared with self-hosted open source budget managers: Firefly III, Actual Budget, Kresus, Sure and ezBookkeeping. Bank connection, budgets, mobile and AI agent."
icon: material/scale-balance
---

# Comparison

Carbure versus the other self-hosted open source budget managers: Firefly III,
Actual Budget, Kresus, Sure and ezBookkeeping.

Carbure targets a household whose accounts are in France and who wants to keep its data at home,
with iPhone and Android apps ([App Store](https://apps.apple.com/fr/app/carbure/id6824057363), [Google Play](https://play.google.com/store/apps/details?id=org.carbure.app)) and an AI agent. The other open source tools below are excellent,
with other strengths: a richer accounting model (Firefly III), envelope budgeting
and a local-first application (Actual Budget), the same woob connection without sharing data
between users (Kresus), the whole net worth and many aggregators worldwide (Sure), or
advanced analysis and the import of almost every file format (ezBookkeeping).

- **Sure**: community fork of Maybe Finance (archived in 2025), it tracks accounts,
  investments and crypto in every currency, with an AI assistant and a built-in MCP server.
  The bank connection goes through aggregators (Plaid, SimpleFIN, Enable Banking…), without
  woob.
- **ezBookkeeping**: lightweight (Go, SQLite possible), it runs on a NAS or a Raspberry Pi,
  imports almost every file format, with an insights explorer and an MCP server. It has
  neither budgets nor a bank connection.

✅ yes · ⚠️ partly · ➖ no

## Hosting { #hebergement }

| | **Carbure** | **Firefly III** | **Actual Budget** | **Kresus** | **Sure** | **ezBookkeeping** |
|---|---|---|---|---|---|---|
| Self-hosted, open source | ✅ MIT | ✅ AGPL-3.0 | ✅ MIT | ✅ AGPL-3.0 | ✅ AGPL-3.0 | ✅ MIT |
| Technologies | PHP, MariaDB/MySQL | PHP (Laravel), SQL database | Node.js, local-first | Node.js, SQL database | Ruby on Rails, PostgreSQL, Redis | Go, Vue.js; SQLite, MySQL or PostgreSQL |
| Docker image | ✅ nginx, PHP and woob included | ✅ | ✅ | ✅ | ✅ | ✅ |
| Web setup wizard, automatic database updates | ✅ | ⚠️ automatic migrations with Docker, no wizard | ⚠️ | ➖ | ⚠️ automatic migrations, onboarding flow | ⚠️ automatic migrations, configuration file |
| Configuration and scheduled sync from the portal | ✅ safe settings, ready-to-copy curl command | ➖ | ➖ | ➖ | ⚠️ automatic sync of the providers, settings in the interface | ➖ |

## Bank data { #donnees-bancaires }

| | **Carbure** | **Firefly III** | **Actual Budget** | **Kresus** | **Sure** | **ezBookkeeping** |
|---|---|---|---|---|---|---|
| Bank connection | Direct, via woob | Importer: Enable Banking, SimpleFIN, Lunch Flow; GoCardless for existing accounts | Enable Banking, SimpleFIN, Pluggy.ai, Akahu; GoCardless closed to new accounts | Direct, via woob | Aggregators: Plaid, SimpleFIN, Enable Banking, Lunch Flow, Akahu… | ➖ file import only |
| No third-party aggregator | ✅ | ➖ | ➖ | ✅ | ➖ | ✅ no bank sync |
| Banks covered | Mostly French | Worldwide, via the providers | Europe, the Americas, New Zealand, via the providers | Mostly French | Worldwide, via the providers | ➖ not applicable |
| File import (CSV, OFX…) | ✅ OFX, QIF, CSV, CAMT.053, duplicates detected | ⚠️ CSV, CAMT.052/053; no OFX nor QIF | ✅ | ⚠️ | ⚠️ CSV, QIF, Mint, YNAB, Actual, PDF statements; no OFX nor CAMT | ✅ CSV, Excel, OFX, QIF, CAMT.052/053, MT940, GnuCash, Firefly III… |
| Multiple currencies | ➖ a single currency (euro by default) | ✅ | ➖ | ⚠️ | ✅ exchange rates | ✅ automatic exchange rates |

## Budget and analysis { #budget-et-analyse }

| | **Carbure** | **Firefly III** | **Actual Budget** | **Kresus** | **Sure** | **ezBookkeeping** |
|---|---|---|---|---|---|---|
| Monthly budgets per category | ✅ | ✅ | ✅ envelopes | ✅ | ✅ | ➖ |
| Subcategories added up in their parent | ✅ | ➖ flat categories, tags | ⚠️ category groups | ➖ | ✅ | ⚠️ two levels of categories |
| Automatic categorization rules | ✅ keywords, suggested after a manual choice | ✅ rich rule engine | ✅ | ✅ | ✅ rule engine, AI categorization | ⚠️ matching at import, AI recognition |
| Checking off transactions | ✅ | ✅ reconciliation | ✅ reconciliation | ➖ | ⚠️ balance reconciliation | ⚠️ reconciliation statements |
| Charts and trends | ✅ trends, comparisons, monthly flow with its transactions | ✅ reports | ✅ custom reports | ✅ | ✅ cash flow, net worth | ✅ many charts, period comparisons |
| Custom indicators | ✅ SQL insights | ➖ | ⚠️ custom reports | ➖ | ⚠️ automatic insights | ✅ insights explorer |
| Recurring transactions, bills | ⚠️ detected automatically, expected this month; no schedule | ✅ | ✅ schedules | ✅ | ✅ detected automatically, bills | ✅ scheduled transactions |
| Savings goals | ➖ | ✅ piggy banks | ✅ goal templates | ➖ | ✅ | ➖ |

## Household and devices { #foyer-et-appareils }

| | **Carbure** | **Firefly III** | **Actual Budget** | **Kresus** | **Sure** | **ezBookkeeping** |
|---|---|---|---|---|---|---|
| Several users on the same data | ✅ administrator and users | ⚠️ separate users | ✅ | ➖ | ✅ household, roles | ⚠️ separate users |
| iPhone | ✅ native app | ⚠️ third-party apps | ⚠️ web app (PWA) | ⚠️ web interface | ⚠️ companion app (login, balances) | ⚠️ web app (PWA) |
| Android | ✅ native app | ⚠️ third-party apps | ⚠️ web app (PWA) | ⚠️ web interface | ⚠️ companion app (login, balances) | ⚠️ web app (PWA) |
| Alerts | ✅ iPhone and Android push, email, your choice of: sync, large expense, rule, budget reached | ⚠️ email, Slack, Discord, Mattermost, webhooks | ➖ | ⚠️ email and Apprise notifications: amount, balance; periodic reports | ⚠️ email per rule | ➖ |

## Open and extensible { #ouvert-et-extensible }

| | **Carbure** | **Firefly III** | **Actual Budget** | **Kresus** | **Sure** | **ezBookkeeping** |
|---|---|---|---|---|---|---|
| REST API | ✅ JWT, OpenAPI | ✅ OAuth2 | ⚠️ JavaScript API | ⚠️ internal | ✅ OpenAPI | ✅ HTTP API, tokens |
| Built-in MCP server for AI agents | ✅ read-only, OAuth for Claude | ➖ community servers | ➖ community servers | ➖ | ✅ read and write, OAuth | ✅ read, adding transactions |

!!! note "Sources"
    Based on each project's documentation, as of October 2026. Spotted a mistake or an omission?
    Report it in an [issue](https://github.com/ltoinel/Carbure/issues).

To go further: the details of Carbure's [features](fonctionnalites.md), then
its [installation with Docker](installation.md) or on a [Synology NAS](synology.md).
