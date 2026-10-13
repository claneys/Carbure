---
icon: material/cog-outline
---

# Configuration

La configuration est lue dans `conf/<env>.ini`, où `<env>` vaut la variable
d'environnement `APP_ENV` (`prod` par défaut, `testing` pour les tests). Les
commentaires commencent par `;` (le `#` n'est pas accepté par PHP).

`data/conf/prod.ini` est écrit par l'**assistant d'installation** à partir de
`conf/prod.sample.ini`, avec les paramètres de la base et des secrets aléatoires
(`jwtsecret`, `password_salt`, `sync_token`) ; avec Docker, il se trouve dans
`/data/conf/prod.ini`. Il contient des secrets : il est ignoré par Git et ne doit jamais
être publié. Après une modification à la main, rien n'est à redémarrer (le fichier est
relu à chaque requête), sauf le conteneur Docker pour `SYNC_INTERVAL`.

!!! tip "Voir et modifier les réglages depuis le portail"
    Inutile d'ouvrir le fichier : un administrateur le consulte dans le portail, onglet
    **Administration → Paramètres**. Les réglages y sont présentés par section, avec leur
    valeur actuelle et une aide, les secrets masqués.

    Les clés marquées ✏️ dans les tableaux ci-dessous s'y modifient directement : `log_level`,
    `log_retention_days`, `savings_category`, `public_url`, `woob_transactions`,
    `woob_logging`, `woob_debug`, `woob_auto_update`, `woob_2fa_wait`, `woob_2fa_retries`,
    `apns_environment`,
    `apns_auth_method`, `apns_bundle_id`, `apns_key_id` et `apns_team_id`. Chaque valeur est
    vérifiée (liste de valeurs, nombre borné, format) avant l'écriture, et le fichier
    précédent est gardé en `prod.ini.bak`. Le serveur web doit pouvoir écrire le fichier ;
    sinon la page est en lecture seule.

    Les autres réglages — base de données, secrets, `woob_path` (commande exécutée par le
    serveur), `regex_label` (qui change les identifiants des transactions), chemins de
    fichiers — sont affichés mais ne se changent que sur le serveur.

D'autres réglages, comme l'activation du serveur MCP, sont enregistrés en base et non dans
ce fichier (voir [Réglages en base](#reglages-en-base)).

## `[global]`

| Clé | Valeurs | Description |
|---|---|---|
| `log_level` ✏️ | `debug`, `info`, `warning`, `error` | Niveau minimal des logs (`data/logs/carbure_AAAAMMJJ.log`). En `debug`, les erreurs PHP sont affichées et les réponses JSON indentées. |
| `log_retention_days` ✏️ | `0` à `3650` | Nombre de jours de conservation des fichiers de log ; les plus anciens sont supprimés à la fin de la synchronisation bancaire. `0` (ou absent) : conservés indéfiniment. `90` dans une nouvelle installation. |
| `development` | `true` / `false` | Indicateur de mode développement, écrit à `false` par l'assistant (sans effet actuellement) |
| `savings_category` ✏️ | nom | Catégorie dont les transactions (avec ses sous-catégories) constituent l'épargne de l'onglet Tendances ; `Epargne` par défaut, casse et accents ignorés |
| `public_url` ✏️ | URL | Facultatif : adresse publique de Carbure (`https://carbure.exemple.fr`), annoncée aux agents IA qui se connectent par OAuth. Par défaut, déduite de la requête : à renseigner si le proxy HTTPS n'envoie pas `X-Forwarded-Proto` |

## `[database]`

| Clé | Description |
|---|---|
| `db_hostname` | Hôte MariaDB/MySQL |
| `db_port` | Port (`3306` par défaut ; `3307` pour MariaDB 10 sur Synology) |
| `db_username`, `db_password` | Identifiants |
| `db_name` | Nom de la base |

## `[cleansing]`

```ini
regex_label["/FACTURE CARTE DU \d{6}\s*/"]="CB "
regex_label["/\s(CARTE.*|CARTE|CART|CAR|CA|C)$/"]=""
regex_label["/(PRLV SEPA.*)(ECH.*|MDT.*)$/"]="$1"
```

Expressions régulières appliquées, dans l'ordre, au libellé en majuscules de chaque
transaction importée.

!!! warning "Ne pas modifier sans migration"
    Le libellé nettoyé entre dans l'UUID des transactions. Changer ces règles fait
    réimporter les transactions existantes sous un autre UUID (doublons).

## `[auth]`

| Clé | Description |
|---|---|
| `jwtsecret` | Secret HMAC des JWT (durée de validité : 30 jours), généré par l'assistant d'installation. Un administrateur le renouvelle depuis le portail (bandeau affiché si le secret est faible) ; le changer déconnecte tous les clients. |
| `password_salt` | Sel des anciens mots de passe SHA-256, utilisé seulement pour les migrer vers bcrypt à la connexion |
| `sync_token` | Secret permettant au planificateur d'appeler `/api/bank/sync` sans JWT. Vide = seul un utilisateur connecté peut lancer la synchro. |

## `[woob]`

| Clé | Description |
|---|---|
| `woob_path` | Commande woob : binaire local (`/usr/bin/woob`), conteneur (`docker run -v …:/root/ ltoinel/woob:3.7 woob`) ou, dans l'image Docker, `env HOME=/data/woob woob` (variable `CARBURE_WOOB_PATH`) |
| `woob_transactions` ✏️ | Nombre d'opérations demandées par appel (`--count`) |
| `woob_logging` ✏️ | Niveau de log de woob, sur sa sortie d'erreur (jamais dans un fichier) : `debug` (`--debug`), `info` (`--verbose`), `warning` (par défaut), `error`/`critical` (`--quiet`) |
| `woob_debug` ✏️ | `true` pour lancer woob en `--debug` (prioritaire sur `woob_logging`), journaliser sa sortie complète et l'afficher dans le journal de synchronisation du portail (administrateurs) |
| `woob_auto_update` ✏️ | `true` pour laisser woob mettre à jour ses modules |
| `woob_2fa_wait` ✏️ | Synchronisation planifiée : secondes d'attente (0 à 300, 15 par défaut) avant de resynchroniser un compte dont la banque demande une validation dans son application |
| `woob_2fa_retries` ✏️ | Synchronisation planifiée : nombre de nouvelles tentatives après cette demande (0 à 5, 1 par défaut ; 0 pour ne pas réessayer) |

## `[apns]`

| Clé | Description |
|---|---|
| `apns_bundle_id` ✏️ | Bundle ID de l'app iOS (sujet APNs) |
| `apns_auth_method` ✏️ | `token` (clé `.p8`, recommandé) ou `certificate` |
| `apns_key_path` | Chemin de la clé `.p8`, absolu ou relatif au dossier `data/` (ex. `conf/certs/AuthKey_XXXX.p8`, soit `data/conf/certs/AuthKey_XXXX.p8`) |
| `apns_key_id`, `apns_team_id` ✏️ | Identifiants Apple Developer |
| `apns_certificate_path`, `apns_certificate_password` | Pour l'authentification par certificat |
| `apns_environment` ✏️ | `production` ou `sandbox` |
| `apns_endpoint` | Facultatif : URL APNs imposée (tests), à la place de celle déduite de `apns_environment` |

Les clés `.p8` placées dans `data/conf/certs/` sont ignorées par Git.

## Réglages en base

Ces réglages, modifiés depuis le portail, sont enregistrés dans la table `settings`
(`name`, `value`) et non dans le fichier :

| Réglage | Valeurs | Modifié dans |
|---|---|---|
| `mcp_enabled` | `1` / `0` (désactivé par défaut) | Onglet **Agent IA**, interrupteur **Serveur MCP activé** (administrateur) |

## Variables d'environnement

| Variable | Rôle |
|---|---|
| `APP_ENV` | Nom du fichier de configuration (`prod` par défaut) |
| `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`, `LANGUAGE`, `CARBURE_WOOB_PATH` | Valeurs proposées par l'assistant d'installation (Docker) ; le mot de passe de l'environnement n'est utilisé que pour cette base |
| `SYNC_INTERVAL` | Docker : intervalle de la synchronisation automatique, en secondes |
