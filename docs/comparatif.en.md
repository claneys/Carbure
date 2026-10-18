---
title: "Comparison: Carbure, Firefly III, Actual Budget and Kresus"
description: "Carbure compared with self-hosted open source budget managers: Firefly III, Actual Budget and Kresus. Bank connection, budgets, mobile and AI agent."
icon: material/scale-balance
---

# Comparison

Carbure versus the other self-hosted open source budget managers: Firefly III,
Actual Budget and Kresus.

Carbure targets a household whose accounts are in France and who wants to keep its data at home,
with iPhone and Android apps (coming soon to the App Store and Google Play) and an AI agent. The other open source tools below are excellent,
with other strengths: a richer accounting model (Firefly III), envelope budgeting
and a local-first application (Actual Budget), or the same woob connection for a
single user (Kresus).

✅ yes · ⚠️ partly · ➖ no

## Hosting { #hebergement }

| | **Carbure** | **Firefly III** | **Actual Budget** | **Kresus** |
|---|---|---|---|---|
| Self-hosted, open source | ✅ MIT | ✅ AGPL-3.0 | ✅ MIT | ✅ AGPL-3.0 |
| Technologies | PHP, MariaDB/MySQL | PHP (Laravel), SQL database | Node.js, local-first | Node.js, SQL database |
| Docker image | ✅ nginx, PHP and woob included | ✅ | ✅ | ✅ |
| Web setup wizard, automatic database updates | ✅ | ➖ | ⚠️ | ➖ |
| Configuration and scheduled sync from the portal | ✅ safe settings, ready-to-copy curl command | ➖ | ➖ | ➖ |

## Bank data { #donnees-bancaires }

| | **Carbure** | **Firefly III** | **Actual Budget** | **Kresus** |
|---|---|---|---|---|
| Bank connection | Direct, via woob | Importer: GoCardless, Enable Banking, SimpleFIN, Salt Edge | GoCardless, Enable Banking, SimpleFIN and others | Direct, via woob |
| No third-party aggregator | ✅ | ➖ | ➖ | ✅ |
| Banks covered | Mostly French | Worldwide, via the providers | Europe and the Americas, via the providers | Mostly French |
| File import (CSV, OFX…) | ✅ OFX, QIF, CSV, CAMT.053, duplicates detected | ✅ | ✅ | ⚠️ |
| Multiple currencies | ➖ euros | ✅ | ➖ | ⚠️ |

## Budget and analysis { #budget-et-analyse }

| | **Carbure** | **Firefly III** | **Actual Budget** | **Kresus** |
|---|---|---|---|---|
| Monthly budgets per category | ✅ | ✅ | ✅ envelopes | ✅ |
| Subcategories added up in their parent | ✅ | ➖ flat categories, tags | ⚠️ category groups | ➖ |
| Automatic categorization rules | ✅ keywords, suggested after a manual choice | ✅ rich rule engine | ✅ | ✅ |
| Checking off transactions | ✅ | ✅ reconciliation | ✅ reconciliation | ➖ |
| Charts and trends | ✅ trends, comparisons, monthly flow with its transactions | ✅ reports | ✅ custom reports | ✅ |
| Custom indicators | ✅ SQL insights | ➖ | ⚠️ custom reports | ➖ |
| Recurring transactions, bills | ⚠️ detected automatically, expected this month; no schedule | ✅ | ✅ schedules | ✅ |
| Savings goals | ➖ | ✅ piggy banks | ✅ goal templates | ➖ |

## Household and devices { #foyer-et-appareils }

| | **Carbure** | **Firefly III** | **Actual Budget** | **Kresus** |
|---|---|---|---|---|
| Several users on the same data | ✅ administrator and users | ⚠️ separate users | ✅ | ➖ |
| iPhone | ✅ native app | ⚠️ third-party apps | ⚠️ web app (PWA) | ⚠️ web interface |
| Android | ✅ native app | ⚠️ third-party apps | ⚠️ web app (PWA) | ⚠️ web interface |
| Alerts | ✅ iPhone and Android push, email, your choice of: sync, large expense, rule, budget reached | ⚠️ email, webhooks | ➖ | ⚠️ email: amount, balance |

## Open and extensible { #ouvert-et-extensible }

| | **Carbure** | **Firefly III** | **Actual Budget** | **Kresus** |
|---|---|---|---|---|
| REST API | ✅ JWT, OpenAPI | ✅ OAuth2 | ⚠️ JavaScript API | ⚠️ internal |
| Built-in MCP server for AI agents | ✅ read-only, OAuth for Claude | ➖ community servers | ➖ | ➖ |

!!! note "Sources"
    Based on each project's documentation, as of October 2026. Spotted a mistake or an omission?
    Report it in an [issue](https://github.com/ltoinel/Carbure/issues).

To go further: the details of Carbure's [features](fonctionnalites.md), then
its [installation with Docker](installation.md) or on a [Synology NAS](synology.md).
