---
title: "Comparatif : Carbure, Firefly III, Actual Budget, Kresus, Sure et ezBookkeeping"
description: "Carbure comparé aux gestionnaires de budget open source à héberger soi-même : Firefly III, Actual Budget, Kresus, Sure et ezBookkeeping. Connexion bancaire, budgets, mobile et agent IA."
icon: material/scale-balance
---

# Comparatif

Carbure face aux autres gestionnaires de budget open source à héberger soi-même : Firefly III,
Actual Budget, Kresus, Sure et ezBookkeeping.

Carbure vise un foyer dont les comptes sont en France et qui veut garder ses données chez lui,
avec des apps iPhone et Android ([App Store](https://apps.apple.com/fr/app/carbure/id6824057363), [Google Play](https://play.google.com/store/apps/details?id=org.carbure.app)) et un agent IA. Les autres outils open source ci-dessous sont excellents,
avec d'autres points forts : un modèle comptable plus riche (Firefly III), le budget par
enveloppes et une application local-first (Actual Budget), la même connexion woob sans
partage des données entre utilisateurs (Kresus), le patrimoine global et de nombreux
agrégateurs dans le monde (Sure), ou des analyses poussées et l'import de presque tous les
formats de fichiers (ezBookkeeping).

- **Sure** : fork communautaire de Maybe Finance (archivé en 2025), il suit comptes,
  placements et crypto dans toutes les devises, avec un assistant IA et un serveur MCP
  intégré. La connexion bancaire passe par des agrégateurs (Plaid, SimpleFIN, Enable
  Banking…), sans woob.
- **ezBookkeeping** : léger (Go, SQLite possible), il tourne sur un NAS ou un Raspberry Pi,
  importe presque tous les formats de fichiers, avec un explorateur d'analyses et un serveur
  MCP. Il n'a ni budgets ni connexion bancaire.

✅ oui · ⚠️ en partie · ➖ non

## Hébergement

| | **Carbure** | **Firefly III** | **Actual Budget** | **Kresus** | **Sure** | **ezBookkeeping** |
|---|---|---|---|---|---|---|
| Hébergé chez soi, open source | ✅ MIT | ✅ AGPL-3.0 | ✅ MIT | ✅ AGPL-3.0 | ✅ AGPL-3.0 | ✅ MIT |
| Technologies | PHP, MariaDB/MySQL | PHP (Laravel), base SQL | Node.js, local-first | Node.js, base SQL | Ruby on Rails, PostgreSQL, Redis | Go, Vue.js ; SQLite, MySQL ou PostgreSQL |
| Image Docker | ✅ nginx, PHP et woob inclus | ✅ | ✅ | ✅ | ✅ | ✅ |
| Assistant d'installation web, mises à jour de la base automatiques | ✅ | ⚠️ migrations automatiques sous Docker, pas d'assistant | ⚠️ | ➖ | ⚠️ migrations automatiques, parcours d'accueil | ⚠️ migrations automatiques, configuration par fichier |
| Configuration et synchronisation planifiée depuis le portail | ✅ réglages sûrs, commande curl prête à copier | ➖ | ➖ | ➖ | ⚠️ synchronisation automatique des fournisseurs, réglages dans l'interface | ➖ |

## Données bancaires

| | **Carbure** | **Firefly III** | **Actual Budget** | **Kresus** | **Sure** | **ezBookkeeping** |
|---|---|---|---|---|---|---|
| Connexion bancaire | Directe, via woob | Importeur : Enable Banking, SimpleFIN, Lunch Flow ; GoCardless pour les comptes existants | Enable Banking, SimpleFIN, Pluggy.ai, Akahu ; GoCardless fermé aux nouveaux comptes | Directe, via woob | Agrégateurs : Plaid, SimpleFIN, Enable Banking, Lunch Flow, Akahu… | ➖ import de fichiers seulement |
| Sans agrégateur tiers | ✅ | ➖ | ➖ | ✅ | ➖ | ✅ pas de synchronisation bancaire |
| Banques couvertes | Surtout françaises | Monde entier, via les fournisseurs | Europe, Amériques, Nouvelle-Zélande, via les fournisseurs | Surtout françaises | Monde entier, via les fournisseurs | ➖ sans objet |
| Import de fichiers (CSV, OFX…) | ✅ OFX, QIF, CSV, CAMT.053, doublons détectés | ⚠️ CSV, CAMT.052/053 ; pas d'OFX ni de QIF | ✅ | ⚠️ | ⚠️ CSV, QIF, Mint, YNAB, Actual, relevés PDF ; pas d'OFX ni de CAMT | ✅ CSV, Excel, OFX, QIF, CAMT.052/053, MT940, GnuCash, Firefly III… |
| Plusieurs devises | ➖ une seule devise (euro par défaut) | ✅ | ➖ | ⚠️ | ✅ taux de change | ✅ taux de change automatiques |

## Budget et analyse

| | **Carbure** | **Firefly III** | **Actual Budget** | **Kresus** | **Sure** | **ezBookkeeping** |
|---|---|---|---|---|---|---|
| Budgets mensuels par catégorie | ✅ | ✅ | ✅ enveloppes | ✅ | ✅ | ➖ |
| Sous-catégories additionnées dans leur parent | ✅ | ➖ catégories à plat, étiquettes | ⚠️ groupes de catégories | ➖ | ✅ | ⚠️ deux niveaux de catégories |
| Règles de catégorisation automatique | ✅ mots-clés, proposées après un choix manuel | ✅ moteur de règles riche | ✅ | ✅ | ✅ moteur de règles, catégorisation par IA | ⚠️ correspondances à l'import, reconnaissance par IA |
| Pointage des transactions | ✅ | ✅ rapprochement | ✅ rapprochement | ➖ | ⚠️ rapprochement du solde | ⚠️ relevés de rapprochement |
| Graphiques et tendances | ✅ tendances, comparaisons, flux du mois avec ses transactions | ✅ rapports | ✅ rapports personnalisés | ✅ | ✅ flux de trésorerie, patrimoine | ✅ nombreux graphiques, comparaisons de périodes |
| Indicateurs personnalisés | ✅ insights SQL | ➖ | ⚠️ rapports personnalisés | ➖ | ⚠️ insights automatiques | ✅ explorateur d'analyses |
| Opérations récurrentes, factures | ⚠️ détectées automatiquement, attendues du mois ; pas d'échéancier | ✅ | ✅ échéances | ✅ | ✅ détectées automatiquement, factures | ✅ transactions planifiées |
| Objectifs d'épargne | ➖ | ✅ tirelires | ✅ modèles d'objectifs | ➖ | ✅ | ➖ |

## Foyer et appareils

| | **Carbure** | **Firefly III** | **Actual Budget** | **Kresus** | **Sure** | **ezBookkeeping** |
|---|---|---|---|---|---|---|
| Plusieurs utilisateurs sur les mêmes données | ✅ administrateur et utilisateurs | ⚠️ utilisateurs séparés | ✅ | ➖ | ✅ foyer, rôles | ⚠️ utilisateurs séparés |
| iPhone | ✅ app native | ⚠️ apps tierces | ⚠️ application web (PWA) | ⚠️ interface web | ⚠️ app compagnon (connexion, soldes) | ⚠️ application web (PWA) |
| Android | ✅ app native | ⚠️ apps tierces | ⚠️ application web (PWA) | ⚠️ interface web | ⚠️ app compagnon (connexion, soldes) | ⚠️ application web (PWA) |
| Alertes | ✅ push iPhone et Android, e-mail, au choix : synchro, grosse dépense, règle, budget atteint | ⚠️ e-mail, Slack, Discord, Mattermost, webhooks | ➖ | ⚠️ e-mail et notifications Apprise : montant, solde ; rapports périodiques | ⚠️ e-mail par règle | ➖ |

## Ouvert et extensible

| | **Carbure** | **Firefly III** | **Actual Budget** | **Kresus** | **Sure** | **ezBookkeeping** |
|---|---|---|---|---|---|---|
| API REST | ✅ JWT, OpenAPI | ✅ OAuth2 | ⚠️ API JavaScript | ⚠️ interne | ✅ OpenAPI | ✅ API HTTP, jetons |
| Serveur MCP intégré pour les agents IA | ✅ lecture seule, OAuth pour Claude | ➖ serveurs communautaires | ➖ serveurs communautaires | ➖ | ✅ lecture et écriture, OAuth | ✅ lecture, ajout de transactions |

!!! note "Sources"
    D'après la documentation de chaque projet, en octobre 2026. Une erreur ou un oubli ?
    Signalez-le dans une [issue](https://github.com/ltoinel/Carbure/issues).

Pour aller plus loin : le détail des [fonctionnalités](fonctionnalites.md) de Carbure, puis
son [installation avec Docker](installation.md) ou sur un [NAS Synology](synology.md).
