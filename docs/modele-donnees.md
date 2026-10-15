---
title: "Modèle de données : tables MariaDB et migrations"
description: "Le schéma de la base de données de Carbure (MariaDB ou MySQL) : utilisateurs, comptes, transactions, catégories, règles, budgets, insights et migrations."
icon: material/database-outline
---

# Modèle de données

Schéma complet : `sql/carbure.sql` (MariaDB/MySQL, InnoDB, `utf8mb4`). Les évolutions
d'une base existante sont dans `sql/migrations/` et appliquées par `Migrator` (voir
[Migrations](#migrations)).

```mermaid
erDiagram
    users ||--o{ devices : "possède"
    users ||--o{ api_tokens : "possède"
    users ||--o{ user_notification : "choisit"
    users ||--o{ budget_alert : "alerté"
    bank_transaction_category ||--o{ budget_alert : "alerte"
    users ||--o{ bank_account : "a ajouté"
    users ||--o{ bank_transaction : "rattachement"
    bank_transaction_category ||--o{ bank_transaction : "catégorise"
    bank_transaction_category ||--o{ bank_transaction_category : "parent"
    bank_transaction_category ||--o{ bank_transaction_category_keyword : "mots-clés"
    bank_transaction_category ||--o{ budget : "budgétée"

    users {
        int id PK
        varchar username UK
        varchar password "bcrypt"
        varchar firstname
        varchar lastname
        varchar email UK
        tinyint is_admin
        varchar language "fr ou en"
        varchar theme "system, light ou dark"
        decimal alert_threshold "seuil d'alerte, NULL = désactivé"
        smallint budget_threshold "seuil des budgets en %, 100"
        datetime last_login
        tinyint failed_logins
        datetime locked_until "NULL = non bloqué"
    }
    devices {
        int id PK
        int user_id FK
        varchar name
        varchar token UK "token APNs"
        timestamp lastLogin
    }
    user_notification {
        int user_id PK
        varchar kind PK "sync, rule, expense, budget"
        tinyint push "1 par défaut"
        tinyint email "0 par défaut"
    }
    budget_alert {
        int user_id PK
        tinyint category PK
        date month PK "premier jour du mois"
        decimal budget "budget alerté"
        decimal spent
        smallint percent
        datetime sent_at
    }
    api_tokens {
        int id PK
        int user_id FK
        varchar name
        char token_hash UK "SHA-256"
        varchar token_hint
        timestamp created_at
        datetime last_used_at
        datetime expires_at "NULL = sans expiration"
    }
    bank_account {
        int id PK
        varchar bank_name "backend woob"
        varchar account_number
        int user_id FK "qui l'a ajouté"
        datetime last_sync_at
        enum last_sync_status "OK, ERROR"
        varchar last_sync_message
    }
    bank_transaction {
        bigint id PK
        varchar uuid UK "md5(rdate, libellé, montant)"
        timestamp imported
        date date "indexée"
        date rdate "date réelle"
        tinyint type
        varchar label
        tinyint category FK
        decimal amount
        varchar card
        tinyint pointed "vérifiée"
        int user FK
    }
    bank_transaction_deleted {
        varchar uuid PK "ignorée à la synchronisation"
        timestamp deleted_at
        int user_id FK
    }
    bank_transaction_category {
        tinyint id PK
        varchar name UK
        tinyint parent_category FK
        enum type "DEBIT, CREDIT, HORS-BUDGET"
        varchar icon
        varchar color
    }
    bank_transaction_category_keyword {
        int id PK
        varchar keyword UK
        tinyint category FK
    }
    budget {
        bigint id PK
        tinyint category FK
        decimal amount
        date date "1er du mois"
    }
    budget_insight {
        int id PK
        varchar name
        varchar color
        varchar icon
        varchar sql "requête avec {month} et {year}"
    }
    settings {
        varchar name PK
        varchar value
    }
    schema_migrations {
        varchar version PK
        timestamp applied_at
    }
```

## Tables

| Table | Rôle |
|---|---|
| `users` | Comptes. `is_admin` donne le profil administrateur ; `language` la langue du portail ; `theme` son thème (`system` : celui du système, `light`, `dark`) ; `alert_threshold` le montant à partir duquel une nouvelle dépense déclenche une notification (`NULL` = désactivé) ; `budget_threshold` le pourcentage du budget du mois d'une catégorie à partir duquel ses dépenses l'alertent (50 à 200, 100 par défaut) ; `last_login` la dernière connexion réussie ; `failed_logins` les échecs de connexion d'affilée et `locked_until` la fin du blocage (24 h après 5 échecs). |
| `devices` | Appareils iOS (token APNs), enregistrés à la connexion. Supprimés avec l'utilisateur. |
| `user_notification` | Canaux choisis par un utilisateur pour un type de notification (`kind` : `sync`, `rule`, `expense`, `budget`) : `push` vers ses appareils, `email` vers l'adresse de son profil. Sans ligne pour un type : push oui, email non, sauf `budget` (ni l'un ni l'autre). Supprimés avec l'utilisateur. |
| `budget_alert` | Alertes de budget envoyées : une par utilisateur, catégorie et mois (`month`, premier jour), avec le budget (`budget`), les dépenses (`spent`) et le pourcentage (`percent`) au moment de l'alerte. Une nouvelle alerte ne part que si le budget du mois dépasse celui déjà alerté. Supprimées avec l'utilisateur ou la catégorie. |
| `api_tokens` | Jetons d'accès des agents IA (serveur MCP) : seule l'empreinte SHA-256 (`token_hash`) est stockée, `token_hint` (début du jeton) sert à le reconnaître ; `expires_at` `NULL` = sans expiration. Supprimés avec l'utilisateur. |
| `bank_account` | Comptes bancaires suivis par le foyer : `account_number@bank_name` forme le `bankId` passé à woob. `user_id` est l'utilisateur qui a ajouté le compte (« Ajouté par ») ; `last_sync_at`, `last_sync_status`, `last_sync_message` décrivent la dernière synchronisation. Unicité `(account_number, bank_name, user_id)` ; l'API refuse en plus un compte déjà suivi par le foyer. |
| `bank_transaction` | Transactions. `uuid` unique sert au dédoublonnage ; `user` = premier utilisateur ayant ajouté le compte (`MIN(user_id)`), sans effet sur la visibilité ; suppression de l'utilisateur interdite tant qu'il a des transactions (`ON DELETE RESTRICT`) ; index `transaction_date` sur `date`. |
| `bank_transaction_deleted` | Transactions supprimées par un utilisateur (`uuid`, `deleted_at`, `user_id` mis à `NULL` si l'utilisateur est supprimé) : la synchronisation et l'import d'un relevé ignorent ces UUID, une transaction supprimée ne revient pas. |
| `bank_transaction_category` | Catégories hiérarchiques (`parent_category`, `0` = racine). La catégorie `0` « Non catégorisé » (`HORS-BUDGET`) est créée par l'installation et protégée. |
| `bank_transaction_category_keyword` | Règles : mots-clés (en majuscules) recherchés dans les libellés pour catégoriser automatiquement. |
| `budget` | Budget d'une catégorie pour un mois (unique par catégorie et date), en `DECIMAL(10,2)`. |
| `budget_insight` | Insights : requête SQL renvoyant une colonne `amount`, avec les marqueurs `{month}` et `{year}` remplacés par des entiers ; `icon` (icône Material, facultative) et `color` (13 couleurs). |
| `settings` | Réglages modifiés depuis le portail (`mcp_enabled`). |
| `schema_migrations` | Migrations appliquées et leur date ; créée par `sql/carbure.sql` (version `2026-10-13_base`) ou par `Migrator`. La version du schéma est la dernière migration enregistrée. |

## Migrations

Les migrations de la version 1.0 (`2026-10-03_audit` à `2026-10-13_rule_notify` : droits
administrateur, alertes, `utf8mb4`, statut de synchronisation, jetons d'API, réglages,
dernière connexion, verrouillage, notification des règles) sont intégrées à
`sql/carbure.sql` et ont été retirées du dépôt. Une base créée depuis `sql/carbure.sql` a la
version `2026-10-13_base`.

Les prochaines migrations iront dans `sql/migrations/` avec leur ligne
`-- applied-if: <requête>`, qui permet de reconnaître une migration déjà appliquée (à la
main, ou par `sql/carbure.sql`). Voir [Développement](developpement.md#migrations-de-base).

## Modèle « foyer »

Toutes les données (comptes, transactions, budgets, catégories, insights) sont partagées
par les utilisateurs. `bank_account.user_id` et `bank_transaction.user` indiquent qui a
ajouté le compte synchronisé, sans restreindre la visibilité. Seuls les appareils, les
jetons d'accès et les préférences (langue, seuil d'alerte) sont propres à chaque
utilisateur.

## Limites connues

- `budget_insight` stocke du SQL exécuté par le serveur : il est encadré (administrateurs
  seulement, mots-clés et tables interdits, transaction en lecture seule), mais des
  indicateurs définis dans le code seraient plus sûrs. Une requête d'insight écrite avec
  `MONTH(date)` / `YEAR(date)` n'utilise pas l'index sur les dates : préférer un intervalle.
