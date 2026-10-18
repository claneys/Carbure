---
title: "Comparatif : Carbure, Firefly III, Actual Budget et Kresus"
description: "Carbure comparé aux gestionnaires de budget open source à héberger soi-même : Firefly III, Actual Budget et Kresus. Connexion bancaire, budgets, mobile et agent IA."
icon: material/scale-balance
---

# Comparatif

Carbure face aux autres gestionnaires de budget open source à héberger soi-même : Firefly III,
Actual Budget et Kresus.

Carbure vise un foyer dont les comptes sont en France et qui veut garder ses données chez lui,
avec des apps iPhone et Android (bientôt sur l'App Store et Google Play) et un agent IA. Les autres outils open source ci-dessous sont excellents,
avec d'autres points forts : un modèle comptable plus riche (Firefly III), le budget par
enveloppes et une application local-first (Actual Budget), ou la même connexion woob pour un
seul utilisateur (Kresus).

✅ oui · ⚠️ en partie · ➖ non

## Hébergement

| | **Carbure** | **Firefly III** | **Actual Budget** | **Kresus** |
|---|---|---|---|---|
| Hébergé chez soi, open source | ✅ MIT | ✅ AGPL-3.0 | ✅ MIT | ✅ AGPL-3.0 |
| Technologies | PHP, MariaDB/MySQL | PHP (Laravel), base SQL | Node.js, local-first | Node.js, base SQL |
| Image Docker | ✅ nginx, PHP et woob inclus | ✅ | ✅ | ✅ |
| Assistant d'installation web, mises à jour de la base automatiques | ✅ | ➖ | ⚠️ | ➖ |
| Configuration et synchronisation planifiée depuis le portail | ✅ réglages sûrs, commande curl prête à copier | ➖ | ➖ | ➖ |

## Données bancaires

| | **Carbure** | **Firefly III** | **Actual Budget** | **Kresus** |
|---|---|---|---|---|
| Connexion bancaire | Directe, via woob | Importeur : GoCardless, Enable Banking, SimpleFIN, Salt Edge | GoCardless, Enable Banking, SimpleFIN et d'autres | Directe, via woob |
| Sans agrégateur tiers | ✅ | ➖ | ➖ | ✅ |
| Banques couvertes | Surtout françaises | Monde entier, via les fournisseurs | Europe et Amériques, via les fournisseurs | Surtout françaises |
| Import de fichiers (CSV, OFX…) | ✅ OFX, QIF, CSV, CAMT.053, doublons détectés | ✅ | ✅ | ⚠️ |
| Plusieurs devises | ➖ euros | ✅ | ➖ | ⚠️ |

## Budget et analyse

| | **Carbure** | **Firefly III** | **Actual Budget** | **Kresus** |
|---|---|---|---|---|
| Budgets mensuels par catégorie | ✅ | ✅ | ✅ enveloppes | ✅ |
| Sous-catégories additionnées dans leur parent | ✅ | ➖ catégories à plat, étiquettes | ⚠️ groupes de catégories | ➖ |
| Règles de catégorisation automatique | ✅ mots-clés, proposées après un choix manuel | ✅ moteur de règles riche | ✅ | ✅ |
| Pointage des transactions | ✅ | ✅ rapprochement | ✅ rapprochement | ➖ |
| Graphiques et tendances | ✅ tendances, comparaisons, flux du mois avec ses transactions | ✅ rapports | ✅ rapports personnalisés | ✅ |
| Indicateurs personnalisés | ✅ insights SQL | ➖ | ⚠️ rapports personnalisés | ➖ |
| Opérations récurrentes, factures | ⚠️ détectées automatiquement, attendues du mois ; pas d'échéancier | ✅ | ✅ échéances | ✅ |
| Objectifs d'épargne | ➖ | ✅ tirelires | ✅ modèles d'objectifs | ➖ |

## Foyer et appareils

| | **Carbure** | **Firefly III** | **Actual Budget** | **Kresus** |
|---|---|---|---|---|
| Plusieurs utilisateurs sur les mêmes données | ✅ administrateur et utilisateurs | ⚠️ utilisateurs séparés | ✅ | ➖ |
| iPhone | ✅ app native | ⚠️ apps tierces | ⚠️ application web (PWA) | ⚠️ interface web |
| Android | ✅ app native | ⚠️ apps tierces | ⚠️ application web (PWA) | ⚠️ interface web |
| Alertes | ✅ push iPhone et Android, e-mail, au choix : synchro, grosse dépense, règle, budget atteint | ⚠️ e-mail, webhooks | ➖ | ⚠️ e-mail : montant, solde |

## Ouvert et extensible

| | **Carbure** | **Firefly III** | **Actual Budget** | **Kresus** |
|---|---|---|---|---|
| API REST | ✅ JWT, OpenAPI | ✅ OAuth2 | ⚠️ API JavaScript | ⚠️ interne |
| Serveur MCP intégré pour les agents IA | ✅ lecture seule, OAuth pour Claude | ➖ serveurs communautaires | ➖ | ➖ |

!!! note "Sources"
    D'après la documentation de chaque projet, en octobre 2026. Une erreur ou un oubli ?
    Signalez-le dans une [issue](https://github.com/ltoinel/Carbure/issues).

Pour aller plus loin : le détail des [fonctionnalités](fonctionnalites.md) de Carbure, puis
son [installation avec Docker](installation.md) ou sur un [NAS Synology](synology.md).
