---
title: "Référence de l'API REST"
description: "Référence de l'API REST de Carbure : authentification JWT, transactions, comptes, synchronisation bancaire, budgets, catégories, règles et administration."
icon: material/api
---

# Référence de l'API

- **Base** : `https://<serveur>/api`
- **Format** : JSON en entrée (corps) et en sortie. Les paramètres peuvent aussi être
  passés en query string ; corps et query string sont fusionnés.
- **Authentification** : en-tête `Authorization: Bearer <token>` (JWT obtenu par
  `POST /user/login`) sur toutes les routes, sauf les routes **publiques** :
  `POST /user/login`, `GET /bank/sync` (JWT ou `sync_token`), `POST /mcp` et `GET /mcp`
  (jeton d'accès ou JWT), `GET /health`, et les routes OAuth des agents IA (métadonnées
  `/.well-known/oauth-*`, `POST /oauth/register`, `GET /oauth/authorize`,
  `POST /oauth/token`). Un test unitaire vérifie cette liste.
- **Paramètres nommés** : chaque clé de la requête correspond à un paramètre de la
  méthode PHP. Une clé inconnue renvoie une erreur 400.
- **Profils** : un **utilisateur** consulte et pointe les transactions, les budgets, les
  insights et les tendances. Un **administrateur** gère en plus les règles, les
  catégories, les comptes, les insights, le serveur MCP, les utilisateurs, la configuration
  et les logs : les routes
  marquées « administrateur » renvoient `403` aux autres. Elles sont déclarées dans leur
  attribut (`#[ApiRoute(..., admin: true)]`) et vérifiées avant l'appel de la méthode ;
  un test unitaire fixe le niveau d'accès (public, utilisateur, administrateur) de chaque
  route, et Swagger l'affiche (extension `x-carbure-access`).

## Erreurs

| Code | Cas |
|---|---|
| 400 | Paramètre manquant, inconnu ou invalide ; JSON invalide |
| 401 | JWT absent, invalide ou expiré ; identifiants invalides ; jeton d'accès MCP invalide |
| 403 | Action réservée à un administrateur ; serveur MCP désactivé ; origine refusée ; code d'installation erroné |
| 404 | Route ou ressource introuvable |
| 405 | `GET /mcp` (le serveur MCP n'ouvre pas de flux) |
| 409 | Conflit (doublon, suppression d'un utilisateur propriétaire de transactions, dernier administrateur…) |
| 423 | Compte bloqué après trop d'échecs de connexion |
| 500 | Erreur serveur (base de données, woob, APNs, migration…) |
| 503 | Carbure n'est pas encore installé (voir [Assistant d'installation](#assistant-dinstallation)) |

```json
{ "error": "Unauthorized - Invalid or missing JWT token", "code": 401, "uid": "6ac106d3912d9" }
```

`uid` identifie la requête dans `data/logs/carbure_AAAAMMJJ.log`.

## Utilisateurs

### `POST /user/login` — publique

| Paramètre | Type | Requis | Description |
|---|---|---|---|
| `username` | string | oui | Identifiant |
| `password` | string | oui | Mot de passe |
| `device` | objet `{name, token}` | non | Appareil iOS à enregistrer pour les notifications |

```bash
curl -X POST https://exemple.fr/api/user/login \
  -H 'Content-Type: application/json' \
  -d '{"username":"alice","password":"…","device":{"name":"iPhone","token":"<token APNs>"}}'
```

```json
{ "exp": 1762000000, "sub": 1, "iat": 1759400000, "token": "eyJ0eXAiOiJKV1Qi…" }
```

Le jeton est valable 30 jours. La date de connexion est enregistrée (`last_login`).

`401` si les identifiants sont faux ; `423` si le compte est bloqué : après 5 échecs
d'affilée, le compte est bloqué 24 heures, même avec le bon mot de passe (le 5e échec
répond déjà `423`). Une connexion
réussie remet le compteur à zéro.

### `POST /user/unlock` — administrateur

`id` : débloque un compte bloqué après trop d'échecs de connexion (`404` si l'utilisateur
n'existe pas).

### `GET /user/me`

Profil de l'utilisateur connecté : `id`, `username`, `firstname`, `lastname`, `email`,
`is_admin`, `language`, `theme` (thème du portail : `system`, `light` ou `dark`),
`alert_threshold` (`null` si les alertes sont désactivées).

```bash
curl https://exemple.fr/api/user/me -H "Authorization: Bearer $TOKEN"
```

### `GET /user`

Liste des utilisateurs pour un administrateur ; l'utilisateur lui-même sinon. Mêmes champs
que `GET /user/me`, plus `last_login` (dernière connexion réussie, `null` si jamais) et
`locked_until` (fin du blocage, `null` si le compte n'est pas bloqué).

### `POST /user` — administrateur

`username`, `password`, `email` (requis), `firstname`, `lastname`, `is_admin` (facultatifs ;
profil utilisateur par défaut). `400` si l'identifiant ou l'e-mail existe déjà.

### `PUT /user`

| Paramètre | Requis | Description |
|---|---|---|
| `id` | oui | Utilisateur à modifier (soi-même, ou n'importe qui pour un administrateur) |
| `email`, `firstname`, `lastname` | non | Nouvelles valeurs |
| `password` | non | Nouveau mot de passe (ignoré si vide) |
| `language` | non | `fr` ou `en` |
| `theme` | non | Thème du portail : `system` (suit la préférence clair/sombre du système, par défaut), `light` ou `dark` ; `400` pour une autre valeur |
| `alertThreshold` | non | Seuil d'alerte en euros pour les nouvelles dépenses ; `""` ou `0` désactive les alertes |
| `is_admin` | non | Profil administrateur (`true`) ou utilisateur (`false`) ; administrateurs seulement, `409` pour retirer le rôle au dernier administrateur |

```bash
curl -X PUT "https://exemple.fr/api/user?id=1" -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' -d '{"language":"en"}'
```

Renvoie l'utilisateur mis à jour.

### `GET /user/notifications`

Canaux de chaque type de notification de l'utilisateur connecté :

```json
{
  "preferences": {
    "sync": {"push": true, "email": false},
    "rule": {"push": true, "email": false},
    "expense": {"push": true, "email": true},
    "budget": {"push": false, "email": false}
  },
  "smtp": true,
  "address": "alice@example.com",
  "email_available": true,
  "budget_threshold": 100,
  "alert_threshold": 200.0,
  "budget_category": null,
  "budgets": [{"id": 1, "name": "Alimentation"}, {"id": 2, "name": "Alimentation › Supermarché"}]
}
```

Types : `sync` (résultat d'une synchronisation), `rule` (nouvelle transaction d'une règle
`notify`), `expense` (dépense au-delà du seuil d'alerte), `budget` (dépenses du mois d'une
catégorie budgétée au-delà du seuil des budgets). Sans choix de l'utilisateur, un type est
envoyé en push et pas par email, sauf `budget`, désactivé sur tous les canaux (à activer).
`smtp` indique qu'un serveur SMTP est configuré, `address` l'adresse du profil (`null` si
aucune), `email_available` que les emails peuvent partir (les deux) et `budget_threshold`
le seuil des alertes de budget, en pourcentage du budget (100 par défaut). `alert_threshold`
est le montant d'une dépense importante (type `expense`, `null` : désactivé, le même que
`GET /user/me`), `budget_category` la catégorie dont le budget est surveillé par les alertes
de budget (`null` : tous les budgets) et `budgets` les catégories de dépenses qui ont un
budget ce mois-ci (une sous-catégorie nommée « Catégorie › Sous-catégorie »), plus celle
surveillée si elle n'en a pas. Les notifications (push et email) sont
rédigées dans la langue du profil (`language`, `fr` par défaut).

### `PUT /user/notifications`

| Paramètre | Obligatoire | Description |
|---|---|---|
| `preferences` | non | `{type: {push?, email?}}`, chaque canal `true`/`false` (ou `"true"`, `"false"`, `1`, `0`) |
| `budgetThreshold` | non | Seuil des alertes de budget (type `budget`) en pourcentage du budget : entier de 50 à 200 (nombre ou chaîne) |
| `alertThreshold` | non | Montant d'une dépense importante (type `expense`) en euros ; `""` ou `0` désactive l'alerte |
| `budgetCategory` | non | Catégorie de dépenses dont le budget est surveillé (type `budget`) ; `0` ou `""` : tous les budgets |

Les types, canaux et réglages absents sont conservés ; type, canal, valeur ou réglage
invalides (dont une catégorie inconnue ou de revenus) : `400`, sans rien enregistrer ;
`budgetCategory` avant la migration `2026-10-23_user_budget_alert_category` : `409`. Renvoie le résultat de `GET /user/notifications`.

```bash
curl -X PUT "https://exemple.fr/api/user/notifications" -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' -d '{"preferences":{"expense":{"email":true}}}'

# Alertes de budget par email, dès 90 % du budget
curl -X PUT "https://exemple.fr/api/user/notifications" -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' -d '{"preferences":{"budget":{"email":true}},"budgetThreshold":"90"}'

# Seulement le budget de la catégorie 4, et les dépenses à partir de 150 €
curl -X PUT "https://exemple.fr/api/user/notifications" -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' -d '{"budgetCategory":"4","alertThreshold":"150"}'
```

### `DELETE /user` — administrateur

`id` : impossible de se supprimer soi-même (400) ou de supprimer un utilisateur
propriétaire de transactions (409).

## Comptes et synchronisation

Les comptes bancaires appartiennent au **foyer** : tous les utilisateurs voient tous les
comptes et leurs transactions. `user_id` d'un compte indique seulement l'utilisateur qui
l'a ajouté.

### `GET /bank`

Comptes bancaires du foyer, visibles par tous les utilisateurs (un compte n'apparaît
qu'une fois) ; `label` est le nom donné au compte (`null` s'il n'en a pas) :

```json
[{ "id": 1, "bankId": "12345678@bnp", "account_number": "12345678", "bank_name": "bnp", "label": "Compte joint" }]
```

### `GET /bank/accounts` — administrateur

Tous les comptes du foyer avec l'utilisateur qui les a ajoutés et le résultat de leur
dernière synchronisation : `id`, `bankId`, `account_number`, `bank_name`, `label`, `user_id` et
`username` (qui l'a ajouté), `last_sync_at`, `last_sync_status` (`OK`, `ERROR` ou `null`),
`last_sync_message` (nombre de nouvelles transactions ou erreur).

### `POST /bank` — administrateur

Suit un compte pour le foyer. Paramètres : `account_number` (identifiant du compte dans
woob), `bank_name` (nom du backend woob) et `label` (facultatif : nom donné au compte,
100 caractères maximum) ; `user_id` du compte est l'administrateur qui l'ajoute. `409` si le foyer suit déjà ce compte, `400` si un identifiant est invalide.

### `PUT /bank` — administrateur

Modifie un compte suivi : `id`, `account_number`, `bank_name` et `label` (facultatif :
absent, le nom actuel est conservé ; vide, il est supprimé). `404` si inconnu, `409` si le
foyer suit déjà le compte visé.

### `DELETE /bank?id=` — administrateur

Ne suit plus le compte ; ses transactions déjà importées sont conservées.

### `GET /bank/backends`, `GET /bank/modules` — administrateur

Banques configurées dans woob (`name`, `module`, `label` : nom donné à la connexion, `null`
si aucun) et banques supportées par woob (`module`, `description`). Une banque doit être configurée dans woob (identifiants bancaires) avant
que ses comptes puissent être synchronisés : depuis le portail (routes ci-dessous).

### `GET /bank/module?module=` — administrateur

Paramètres demandés par un module woob : `module`, `description`, `fields` (`key`, `label`,
`description`, `default`, `required`, `masked`, `choices`). Rien de secret n'est renvoyé.
Un module pas encore installé l'est d'abord ; `409` si le dépôt de woob n'est pas encore
approuvé (l'installer ferait confiance à sa clé, voir `GET /bank/woob/update`), comme
pour `POST /bank/backend`.

### `POST /bank/backend` — administrateur

Configure une banque dans woob : `module`, `backend` (nom, `[a-z0-9_-]`), `settings`
(objet des paramètres du module ; seules ses clés sont transmises, valeurs sur une ligne
sans espace, choix vérifiés), `label` (facultatif : nom donné à la connexion, 100
caractères max, par exemple « Compte joint »). Les identifiants sont confiés à woob, ni
stockés ni journalisés par Carbure (`settings` est masqué dans les logs). Renvoie
`backend`, `label` et les `accounts` trouvés (même format que `/bank/discover`). `409` si
une banque de ce nom existe déjà dans woob ou si son module n'est pas installé et le dépôt
de woob pas encore approuvé, `400` si un paramètre manque ou si woob refuse.

### `PUT /bank/backend` — administrateur

Renomme une connexion bancaire : `backend` (nom dans woob), `label` (nom donné à la
connexion ; vide le supprime). Le nom dans woob, qui identifie les comptes et les
transactions, ne change pas. Renvoie `name` et `label`. `404` si la banque n'est pas
configurée dans woob, `400` si le nom est invalide ou trop long.

### `DELETE /bank/backend` — administrateur

Supprime une connexion bancaire : `backend` (nom dans woob, dans la query string). woob
l'oublie avec ses identifiants (`woob config remove`), son nom donné et ses comptes suivis
sont supprimés ; les transactions déjà importées sont conservées. Renvoie `true`. `404` si
la banque n'est pas configurée dans woob, `400` si le nom est invalide, `500` si woob ne
l'a pas supprimée.

### `GET /bank/woob` — administrateur

Sur une installation neuve (woob jamais lancé), toutes les routes qui lancent woob
répondent `409` tant que la clé de son dépôt n'est pas approuvée (`GET /bank/woob/update`) :
au premier lancement, woob ferait confiance à cette clé de lui-même.

État du dépôt des modules bancaires de woob : `repository` (URL), `trusted` (clé déjà
approuvée), `fingerprint` (empreinte SHA-1 de la clé approuvée, sinon de celle que woob
demandera d'approuver, à comparer avec celle des mainteneurs de woob ; `null` si le dépôt
est injoignable) et `error` (cause, `null` sinon).

### `GET /bank/woob/update` — administrateur (flux SSE)

Met à jour les dépôts et les modules bancaires (`woob config update`). Paramètre
facultatif `fingerprint` (empreinte SHA-1 confirmée par l'administrateur, `400` si ce
n'est pas 40 caractères hexadécimaux). Tant que la clé du dépôt n'est pas approuvée,
woob (qui lui ferait confiance de lui-même) n'est lancé que si `fingerprint` est
l'empreinte de la clé que Carbure télécharge juste avant, et la clé conservée par woob
doit être celle-là (sinon elle est supprimée). Chaque ligne de woob est envoyée
(`data: <ligne>`), puis le résultat : `Woob updated`, `Woob repository not trusted:
<empreinte>` (woob non lancé : à comparer avant de réessayer avec elle) ou `Woob update
failed: <cause>` (dont `synchronization in progress` : jamais pendant une
synchronisation).

### `GET /bank/discover` — administrateur

Interroge woob (`woob bank list`) et renvoie les comptes des banques configurées :
`bankId`, `account_number`, `bank_name`, `label`, `balance`, `currency`, `followed`. Peut
prendre plusieurs secondes (connexion aux banques).

| Paramètre | Description |
|---|---|
| `backend` (facultatif) | Seulement les comptes de cette connexion (backend woob, `woob bank list -b <backend>`) ; `400` si le nom est invalide |

### `GET /bank/sync` — flux SSE { #bank-sync }

Lance la synchronisation de **tous** les comptes, ou d'un seul avec
`?account=<account_number>@<bank_name>` (`404` s'il n'est pas suivi). La date et le résultat
sont enregistrés pour chaque compte. Accès autorisé avec :

- un JWT valide (n'importe quel utilisateur), **ou**
- le `sync_token` de la configuration, dans l'en-tête `X-Sync-Token`.

!!! warning "Paramètre `?token=` obsolète"
    Le `sync_token` passé dans l'URL (`?token=`) est encore accepté pour les tâches planifiées
    déjà configurées, mais il est **obsolète** : l'URL est écrite dans les logs du proxy, de
    nginx et l'historique du shell. La réponse porte alors les en-têtes
    `Deprecation: @1792108800` (obsolète depuis le 16/10/2026, [RFC 9745](https://www.rfc-editor.org/rfc/rfc9745))
    et `Link: <https://carbure.org/api/#bank-sync>; rel="deprecation"`, et un avertissement
    (sans le jeton) est journalisé. Remplacez-le par l'en-tête `X-Sync-Token` ; s'ils sont
    présents tous les deux, l'en-tête l'emporte.


Sans JWT ni jeton valide (en-tête ou URL) : `401`. `?interactive=true` (avec `account`, pour un utilisateur connecté seulement,
`400` sinon) autorise woob à déclencher une authentification forte : woob est lancé sur un
pseudo-terminal (`tools/woob-tty.py`) et ses messages sont relayés en
`data: Bank message <compte>: <message>` pendant qu'il attend la validation dans
l'application de la banque. Sans ce paramètre, une banque qui exige une authentification
forte échoue avec `…interactive mode to perform a two-factor authentication`.

Les opérations à venir sont facultatives : si leur appel à woob échoue (hors
authentification forte), l'historique est quand même synchronisé, l'échec est envoyé en
`data: Coming failed <compte>: <erreur>` et le compte reste `OK`, avec le résultat
`<n> new (coming failed: <erreur>)`.

Pour le planificateur (`sync_token`, jamais en mode interactif), un compte dont la banque
demande une validation dans son application est resynchronisé après `woob_2fa_wait`
secondes (15 par défaut), jusqu'à `woob_2fa_retries` fois (1 par défaut) : la validation a
pu être faite entre-temps. Seul ce compte attend (`data: Waiting for a validation <compte>:
new attempt in 15 s (1/1)`, puis des `: heartbeat`) ; seul le résultat de la dernière
tentative est enregistré et notifié.

La réponse est un flux `text/event-stream` : messages `data: …` (progression,
résultat de chaque étape) et commentaires `: heartbeat` toutes les 15 s pendant
l'exécution de woob. Une seule synchronisation peut tourner à la fois (sinon :
`data: Synchronization already in progress`).

```bash
curl -N -H "X-Sync-Token: $SYNC_TOKEN" https://exemple.fr/api/bank/sync
```

```text
data: Syncing 12345678@bnp (coming)...
data: Done 12345678@bnp (coming): 3 received, 1 new
data: Syncing 12345678@bnp (history)...
data: Done 12345678@bnp (history): 100 received, 4 new
data: Notifying users of 12345678@bnp...
data: Updating missing categories...
data: Checking the budgets...
data: Synchronization complete
```

Après chaque compte, **tous les utilisateurs** du foyer reçoivent une notification (succès
ou échec, nombre de transactions à vérifier), chaque **nouvelle** dépense (transaction
absente jusque-là) dont le montant atteint le seuil `alert_threshold` d'un utilisateur lui
envoie une notification « Dépense importante à vérifier », et chaque nouvelle transaction
d'une règle `notify` est signalée au foyer. Une fois les transactions catégorisées, les
**budgets du mois en cours** sont vérifiés (alerte `budget`, aussi après un import). Chaque
notification part dans la langue de son destinataire, sur les canaux (push, email) qu'il a
choisis (voir [`GET /user/notifications`](#get-usernotifications)).

## Transactions

### `GET /transaction`

| Paramètre | Requis | Description |
|---|---|---|
| `month`, `year` | non | Mois des transactions (mois courant par défaut) |
| `category` | non | Limite aux transactions de cette catégorie **et de ses sous-catégories** |

Les transactions sont triées par date réelle décroissante. Champs : `id`, `uuid`,
`imported`, `date`, `rdate`, `type`, `label`, `category`, `amount`, `card`, `bank_name`
et `account_number` (compte d'origine ; `null` pour les transactions antérieures à leur
ajout), `pointed`, `user`.

```bash
curl "https://exemple.fr/api/transaction?month=9&year=2026&category=1" -H "Authorization: Bearer $TOKEN"
```

Types : 1 virement, 2 prélèvement, 3 chèque, 4 remise de chèque, 5 remboursement,
6 retrait DAB, 7 facture carte, 8 dépense, 9 commissions, 12 carte en cours.

### `GET /transaction/periods`

Mois qui ont des transactions, du plus récent au plus ancien : `[{"year": 2026, "month": 10}, …]`.
Le portail en tire les listes de mois et d'années.

### `GET /transaction/recurring`

Transactions récurrentes d'un mois : retrouvées environ une fois par mois vers le même jour
(salaire, loyer, abonnements, factures). Une série regroupe les transactions de même sens
(débit ou crédit) dont le libellé, chiffres retirés (références, dates), est le même. Elle
est récurrente si elle apparaît dans 3 mois au moins de la période, 1,5 fois par mois au
plus, 3 fois sur 4 à 5 jours près du même jour, et encore active (vue dans le mois ou le
mois précédent). Un paiement par carte doit en plus garder le même montant à 20 % près
(un abonnement, pas le restaurant) ; un prélèvement ou un virement peut varier.

| Paramètre | Requis | Description |
|---|---|---|
| `month`, `year` | non | Mois (mois courant par défaut) |
| `months` | non | Nombre de mois précédents examinés (2 à 24, défaut 6) |

Réponse, triée par jour : `[{"label", "category", "amount" (dernier montant), "average",
"variable" (montant changeant de plus de 10 %), "day" (jour habituel), "months" (nombre de
mois où elle apparaît), "last" (date de la dernière), "ids" (ses transactions du mois),
"status"}]`. `status` vaut `received`, ou `expected` si elle n'est pas encore dans le mois.

```bash
curl "https://exemple.fr/api/transaction/recurring?month=10&year=2026" -H "Authorization: Bearer $TOKEN"
```

### `GET /transaction/search`

| Paramètre | Requis | Description |
|---|---|---|
| `query` | oui | Texte recherché dans le libellé (2 caractères minimum, insensible à la casse) |
| `limit` | non | Nombre maximal de résultats (1 à 500, défaut 100) |

```bash
curl "https://exemple.fr/api/transaction/search?query=amazon" -H "Authorization: Bearer $TOKEN"
```

### `POST /transaction/import/preview`

Lit un relevé bancaire sans rien enregistrer.

| Paramètre | Requis | Description |
|---|---|---|
| `file` | oui | Contenu du fichier encodé en base64 (1 Mo au plus avant encodage) ; jamais écrit dans les logs |
| `filename` | non | Nom du fichier (son extension aide à reconnaître le format) |

Formats : OFX/QFX (1.x SGML et 2.x XML), QIF, CAMT.053 (ISO 20022) et CSV : lu avec le
**profil de sa banque** quand son en-tête est celui d'un export connu (voir
[Fonctionnalités](fonctionnalites.md#import-de-releves-bancaires)), sinon séparateur détecté
et colonnes devinées d'après l'en-tête (date ou date d'opération, libellé, montant ou débit
et crédit). L'ordre jour/mois des dates est déduit du fichier ; le type d'opération,
du libellé (`CB`, `PRLV`, `VIR`, `RETRAIT`…) ou à défaut du fichier.

Renvoie `format` (`ofx`, `qif`, `camt`, `csv`), `bank` (banque dont le profil a lu un CSV :
`id` et `name`, par exemple `{"id": "boursorama", "name": "Boursorama / BoursoBank"}` ;
`null` pour un CSV aux colonnes devinées ou un autre format), `account` (numéro de compte ou IBAN lu dans
le fichier, sinon `null`), `from`, `to`, `counts` (`new`, `known`, `duplicate`) et `rows` :
`index`, `date`, `rdate`, `amount`, `label` (nettoyé comme à la synchronisation), `type`,
`status` et, pour un doublon probable, `match` (`id`, `date`, `label` de la transaction
existante).

| Statut | Signification |
|---|---|
| `new` | Nouvelle transaction |
| `known` | Même UUID qu'une transaction existante : elle serait fusionnée. Des lignes identiques du fichier sont autant de transactions (chacune son UUID) |
| `duplicate` | Même montant qu'une transaction existante à 3 jours près (date ou date réelle) : probablement la même, synchronisée avec un autre libellé |

Erreurs : `400` (base64 invalide, fichier vide), `413` (fichier trop gros, plus de 5 000
transactions), `422` (format non reconnu, colonnes CSV introuvables, XML invalide).

### `POST /transaction/import`

Mêmes paramètres, plus `selected` (facultatif) : les `index` des lignes à importer ; par
défaut, les lignes `new`. Les lignes `known` ne sont jamais importées. Les transactions
appartiennent à l'utilisateur connecté, puis les règles de catégorisation s'appliquent
quelle que soit leur date. Renvoie `imported`, `categorized`, `from` et `to` (période des
lignes choisies).

```bash
curl -X POST https://exemple.fr/api/transaction/import -H "Authorization: Bearer $TOKEN" \
     -H "Content-Type: application/json" \
     -d "{\"file\": \"$(base64 -w0 releve.ofx)\", \"filename\": \"releve.ofx\"}"
```

### `PUT /transaction/category`

`id`, `category` : affecte la catégorie et pointe la transaction (`autoPointed=false`
pour ne pas la pointer).

### `PUT /transaction/pointed`

`id`, `pointed` (booléen, défaut `true`) : marque la transaction comme vérifiée ou non.

```bash
curl -X PUT https://exemple.fr/api/transaction/pointed -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' -d '{"id":42,"pointed":false}'
```

### `DELETE /transaction`

`id` : supprime la transaction (`404` si elle n'existe pas). Son `uuid` est mémorisé
(table `bank_transaction_deleted`) : la synchronisation de son compte et l'import d'un
relevé l'ignorent, elle ne revient pas (dans l'aperçu d'un import, la ligne est `known`
avec `deleted: true`).

```bash
curl -X DELETE "https://exemple.fr/api/transaction?id=42" -H "Authorization: Bearer $TOKEN"
```

## Budgets et tendances

### `GET /budget`

`month`, `year`, `category` (catégorie parente, `0` = racine). Pour chaque catégorie :
`id`, `name`, `type`, `icon`, `color`, `budget`, `consummed` (somme absolue des
transactions de la catégorie et de ses sous-catégories), `progress` (%), `children`
(nombre de sous-catégories), `children_budget` (somme de leurs budgets) et `budget_mode` :
`own` (montant défini pour la catégorie) ou `children` (catégorie avec sous-catégories et
sans montant défini : `budget` est alors la somme des budgets des sous-catégories).

### `GET /budget/flow`

`month`, `year` (mois courant par défaut) : flux d'argent du mois, pour le diagramme
« Flux du mois ». Les montants sont regroupés par catégorie de premier niveau ; les
catégories `HORS-BUDGET` (virements internes) sont exclues, sauf la catégorie d'épargne
(`savings_category`) ; les transactions non catégorisées sont conservées.

| Champ | Description |
|---|---|
| `month` | `AAAA-MM` |
| `income`, `expenses` | Revenus et dépenses par catégorie de premier niveau : `id`, `name`, `color`, `icon`, `amount` (positif), du plus grand au plus petit |
| `savings` | Montant net versé sur la catégorie d'épargne et ses sous-catégories |
| `totalIncome`, `totalExpenses` | Totaux des revenus et des dépenses |
| `balance` | Reste : revenus − dépenses − épargne (si elle est positive) ; négatif en cas de déficit |

### `GET /budget/flow/transactions`

Transactions d'un nœud du diagramme de flux, de la plus récente à la plus ancienne, avec
le même classement que `GET /budget/flow`.

| Paramètre | Requis | Description |
|---|---|---|
| `kind` | oui | `income` (revenus), `expense` (dépenses) ou `savings` (épargne) |
| `categories` | non | Identifiants des catégories de premier niveau, séparés par des virgules (`0` = non catégorisées) ; toutes par défaut. Ignoré pour `savings` |
| `month`, `year` | non | Mois courant par défaut |

Renvoie les colonnes de `bank_transaction` et `top` (catégorie de premier niveau).

### `POST /budget`

`category`, `amount` (requis), `month`, `year` (mois courant par défaut) : crée ou met à
jour le budget de la catégorie pour le mois. Pour une catégorie avec sous-catégories, le
montant remplace la somme de leurs budgets ; `0` revient à cette somme.

### `POST /budget/copy`

`month`, `year` (mois cible, mois courant par défaut), `overwrite` (`false` par défaut) :
recopie les budgets du mois précédent vers le mois cible. Sans `overwrite`, seules les
catégories sans budget dans le mois cible sont recopiées ; avec `overwrite`, tous les
budgets du mois précédent remplacent ceux du mois cible. Renvoie `month`, `source` (mois
précédent, `AAAA-MM`), `copied` (budgets écrits) et `overwrite`.

### `GET /budget/trends`

| Paramètre | Requis | Description |
|---|---|---|
| `months` | non | Nombre de mois (1 à 60, défaut 24) |
| `offset` | non | Nombre de mois entre le mois courant et le dernier mois de la période (défaut 0). Sert aux comparaisons : `offset=months` pour la période précédente, `offset=12` pour la même période l'an dernier |

Renvoie un élément par mois (du plus ancien au plus récent, mois vides inclus) :

| Champ | Description |
|---|---|
| `month` | `AAAA-MM` |
| `debit` | Dépenses du mois, catégories hors budget exclues (montant positif) |
| `credit` | Revenus du mois, catégories hors budget exclues |
| `offBudget` | Solde des catégories `HORS-BUDGET` (ex. virements vers l'épargne) |
| `planned` | Somme des budgets des catégories de premier niveau (montant défini, ou somme des sous-catégories) |
| `savings` | Montant net versé sur la catégorie d'épargne (`savings_category`, `Epargne` par défaut) et ses sous-catégories, positif quand on épargne |

```bash
curl "https://exemple.fr/api/budget/trends?months=6&offset=12" -H "Authorization: Bearer $TOKEN"
```

```json
[{ "month": "2025-05", "debit": 2130.37, "credit": 5114, "offBudget": -500, "planned": 2330, "savings": 2983.63 }]
```

## Insights

### `GET /budget/insights`

`month`, `year` : valeur de chaque insight pour le mois (`id`, `name`, `color`, `icon`,
`amount`). Une requête en échec donne `0` sans bloquer les autres.

### `GET /budget/insights/history`

`year` : pour chaque insight, la liste `history` des montants mois par mois.

### `GET /insight`, `POST /insight`, `PUT /insight`, `DELETE /insight?id=` — administrateur

Gestion des insights, avec leur requête SQL :

| Paramètre | Requis | Description |
|---|---|---|
| `id` | `PUT`, `DELETE` | Insight à modifier ou supprimer (`404` si inconnu) |
| `name` | oui | 1 à 20 caractères |
| `color` | oui | `red`, `orange`, `amber`, `lime`, `green`, `teal`, `cyan`, `blue`, `indigo`, `purple`, `pink`, `brown` ou `gray` |
| `sql` | oui | Requête `SELECT … AS amount` (500 caractères au plus) ; `{month}` et `{year}` sont remplacés par le mois demandé |
| `icon` | non | Icône Material (`[a-z0-9_]`) ; choisie d'après le nom si vide |

La requête doit être un unique `SELECT` renvoyant une colonne `amount`, sans `;` ni
commentaire, sans mot-clé d'écriture, d'administration, de fichier ou de temporisation, et
ne peut pas lire les tables `users`, `api_tokens`, `devices` ni les schémas système. Elle
est testée sur le mois courant avant l'enregistrement (`400` avec la cause sinon) et
exécutée dans une transaction en lecture seule. `POST` et `PUT` renvoient l'insight avec
le montant du mois courant.

### `POST /insight/check` — administrateur

`sql`, `month`, `year` (mois courant par défaut) : vérifie une requête sans l'enregistrer
(mêmes contrôles), pendant la saisie. Renvoie `{"valid": true, "amount": …}` ou
`{"valid": false, "error": "…"}` (toujours `200`).

## Catégories et règles

### `GET /category`

Toutes les catégories : `id`, `name`, `parent_category`, `type` (`DEBIT`, `CREDIT`,
`HORS-BUDGET`), `icon`, `color`.

### `POST /category`, `PUT /category`, `DELETE /category?id=` — administrateur

Crée ou modifie une catégorie (`name`, `type` : `DEBIT`, `CREDIT` ou `HORS-BUDGET`,
`parent_category`, `icon`, `color` ; `id` pour la modification) ou la supprime. `409` si le
nom existe déjà ou si la catégorie à supprimer a des sous-catégories, `400` pour la catégorie
par défaut (0) ou un parent qui n'est pas une catégorie principale. À la suppression, les
transactions passent en catégorie 0 et les budgets et règles de la catégorie sont supprimés.

### `GET /category/keyword`

Règles de catégorisation automatique : `id`, `keyword`, `category`, `category_name`.

### `POST /category/keyword` — administrateur

`keyword` (1 à 60 caractères, enregistré en majuscules), `category` : ajoute une règle.
Erreurs : 400 mot-clé vide, 404 catégorie inconnue, 409 mot-clé déjà existant.

```bash
curl -X POST https://exemple.fr/api/category/keyword -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' -d '{"keyword":"decathlon","category":9}'
```

### `PUT /category/keyword` — administrateur

`id`, `keyword`, `category`, `notify` : modifie une règle (`409` si le mot-clé existe déjà).
`POST /category/keyword` accepte aussi `notify` : chaque nouvelle transaction synchronisée
qui contient le mot-clé envoie alors une notification à tous les utilisateurs du foyer.

### `GET /category/keyword/count?id=` — administrateur

Nombre de transactions dont le libellé contient le mot-clé (`matching`), dont celles déjà
dans la catégorie de la règle (`categorized`).

### `DELETE /category/keyword` — administrateur

`id` : supprime une règle (404 si inconnue).

### `POST /category/keyword/apply` — administrateur

Applique toutes les règles à toutes les transactions de la base, déjà catégorisées
comprises (la première règle trouvée dans le libellé l'emporte). Renvoie
`{ "updated": <nombre de transactions dont la catégorie a changé> }`. Créer ou modifier une
règle (`POST`/`PUT /category/keyword`) l'applique aussi immédiatement à tout l'historique et
renvoie `applied`, le nombre de transactions recatégorisées.

## Appareils

### `GET /device`

Appareils de l'utilisateur connecté : `id`, `user_id`, `name`, `token`, `lastLogin`.

### `DELETE /device`

`id` : supprime un appareil de l'utilisateur connecté (il ne recevra plus de
notifications). 404 si l'appareil n'existe pas ou appartient à un autre utilisateur.

### `POST /device/push`

Envoie une notification de test à tous les appareils de l'utilisateur connecté.

## Agents IA (MCP)

### `GET /token`, `POST /token`, `DELETE /token?id=`

Jetons d'accès de l'utilisateur connecté, pour le serveur MCP. `POST` (`name` : 1 à 50
caractères ; `days` : `30`, `90`, `365`, ou `0`/vide pour sans expiration) renvoie le jeton
(`token`, préfixe `cbt_`) **une seule fois**, avec `id`, `name`, `token_hint` et
`expires_at` ; `409` au-delà de 20 jetons. La liste ne donne que `id`, `name`,
`token_hint`, `created_at`, `last_used_at`, `expires_at` (`null` = sans expiration) et
`expired`. `DELETE` révoque un jeton (`404` s'il n'existe pas ou appartient à un autre
utilisateur).

### `GET /mcp/settings`, `PUT /mcp/settings`

`{"enabled": true|false}` : état du serveur MCP (désactivé par défaut). `PUT` (`enabled`)
l'active ou le désactive — administrateur.

### `POST /mcp` — publique (jeton d'accès)

Serveur MCP (JSON-RPC 2.0, transport Streamable HTTP) en lecture seule, authentifié par
`Authorization: Bearer <jeton>` (ou `?token=`). `GET /mcp` répond `405`. Voir
[Agents IA (MCP)](mcp.md).

### OAuth 2.1 des agents IA — publiques (sauf indication)

Pour les agents qui ne prennent que l'URL du serveur MCP (Claude web, Desktop, mobile).
Réponses et erreurs au format OAuth (`{"error", "error_description"}`), pas au format de
l'API.

| Route | Rôle |
|---|---|
| `GET /.well-known/oauth-protected-resource` (et `…/api/mcp`) | Métadonnées du serveur MCP (RFC 9728) : `resource`, `authorization_servers` |
| `GET /.well-known/oauth-authorization-server` | Métadonnées OAuth (RFC 8414) : points d'accès, `S256`, méthodes d'authentification du client |
| `POST /api/oauth/register` | Enregistrement dynamique (RFC 7591) : `redirect_uris` (HTTPS, ou HTTP sur `localhost`), `client_name`, `token_endpoint_auth_method` (`none`, `client_secret_post`, `client_secret_basic`) ; `201` avec `client_id` (et `client_secret`) |
| `GET /api/oauth/authorize` | `response_type=code`, `client_id`, `redirect_uri`, `state`, `code_challenge` (`S256`) : `302` vers la page de consentement du portail, ou vers l'agent avec `error=` ; `400` si le client ou l'URI de retour est inconnu |
| `GET /api/oauth/client?request=` — JWT | Nom de l'agent et hôte de retour, pour la page de consentement |
| `POST /api/oauth/approve` — JWT | `request`, `approved` : `{"redirect"}` vers l'agent, avec le code (5 minutes, usage unique) ou `error=access_denied` |
| `POST /api/oauth/token` | Formulaire ou JSON : `grant_type=authorization_code` (`code`, `redirect_uri`, `client_id`, `code_verifier`) ou `refresh_token` ; `{"access_token", "token_type": "Bearer", "expires_in", "refresh_token"}` |

Toutes répondent `403` (`access_denied`) quand le serveur MCP est désactivé.

## Système

### `GET /health` — publique

État de l'instance pour le `HEALTHCHECK` Docker et les outils de supervision, sans
authentification ni donnée : `{"status":"ok","version":"1.2.0","database":"ok","schema":"<version du schéma>","min_mobile_version":"1.0"}`,
`500` si la base ne répond pas, `{"status":"setup","version":"…","min_mobile_version":"1.0"}` tant que Carbure n'est
pas installé. `version` est la version de Carbure (fichier `VERSION`, `dev` hors release).
`min_mobile_version` est la plus ancienne version de l'app iOS (son `MARKETING_VERSION`)
que le serveur prend en charge : une app plus ancienne invite son utilisateur à la mettre
à jour. Elle est fixée dans le code (`Carbure\Lib\MobileApp::MIN_VERSION`) et n'est relevée
que lorsqu'une évolution de l'API ne peut plus être lue par les anciennes versions de l'app,
une fois la nouvelle version publiée sur l'App Store.

### `GET /system/logs` — administrateur

Fichiers de log de l'instance, les plus récents d'abord : `[{name, size, modified}]`.

### `GET /system/logs/retention` — administrateur

Durée de conservation des fichiers de log : `{"days": 90}` (`0` : conservés indéfiniment).
Les fichiers plus anciens sont supprimés à la fin de la synchronisation bancaire
(paramètre `log_retention_days`).

### `GET /system/logs/entries` — administrateur

Entrées d'un fichier, les plus récentes d'abord. Paramètres : `file` (nom renvoyé par
`/system/logs`), `level` facultatif (niveau minimal : `DEBUG`, `INFO`, `WARN`, `ERROR`),
`search` facultatif (texte ou identifiant de requête), `limit` (1 à 1000, 200 par défaut).
Réponse : `{file, entries: [{time, level, uid, caller, message, ip, user, method, path}], truncated}`
(`ip`, `user`, `method` et `path` : entrées au format JSON, absents des fichiers plus
anciens ; le contexte d'une entrée suit son message) ; seuls les
2 derniers Mo sont lus (`truncated`) et les jetons sont masqués. 400 pour un nom de fichier
invalide, 404 s'il n'existe pas.

### `GET /system/schema` — administrateur

Version du schéma de la base et migrations à appliquer :
`{"version": "2026-10-13_base", "pending": []}`.

### `POST /system/migrate` — administrateur

Applique les migrations en attente (bouton **Mettre à jour** du portail) :
`{"version": "…", "applied": ["…"]}` ; `500` avec la cause si une migration échoue (les
suivantes ne sont pas exécutées).

### `POST /system/jwt-secret` — administrateur

Remplace le `jwtsecret` de la configuration par une valeur aléatoire : toutes les sessions
(portail et application iOS) prennent fin. `GET /system/schema` indique `weakJwtSecret`
quand le secret est celui de l'exemple (ou trop court) : le portail propose alors de le
renouveler.

### `GET /system/config` — administrateur

Le fichier de configuration, par section : `file` (chemin dans l'instance), `writable`, et
`sections` (`name`, `settings`). Chaque réglage : `key`, `value` (masquée pour les secrets ;
`true`/`false` pour un interrupteur ; une liste pour `regex_label`), `set`, `secret`,
`editable`, `kind` (`select`, `bool`, `number`, `text`, `url`, `email`, `password`, `list`) et, selon le type,
`options`, `min`, `max`. Les réglages modifiables absents du fichier sont listés avec
`set: false`.

### `PUT /system/config` — administrateur

`values` : les nouvelles valeurs par clé, uniquement parmi les réglages modifiables
(`log_level`, `log_retention_days`, `savings_category`, `public_url`, `woob_transactions`, `woob_logging`,
`woob_debug`, `woob_auto_update`, `woob_2fa_wait`, `woob_2fa_retries`, `apns_environment`, `apns_auth_method`, `apns_bundle_id`,
`apns_key_id`, `apns_team_id`, `smtp_host`, `smtp_port`, `smtp_encryption`, `smtp_auth`,
`smtp_username`, `smtp_password`, `smtp_from`, `smtp_from_name`). Toutes les valeurs sont vérifiées avant d'écrire quoi que
ce soit (`400` sinon, fichier inchangé) ; l'ancien fichier est gardé en `.bak`. Renvoie la
configuration comme `GET`.

### `GET /system/sync` — administrateur

`reveal` (facultatif, `false` par défaut) : `url` de `/api/bank/sync`, `token` (le
`sync_token`, masqué sauf avec `reveal=true` ; `null` s'il n'y en a pas), `masked` et
`accounts` (les comptes suivis, pour `?account=`) et `header` (`X-Sync-Token`, l'en-tête
qui porte le jeton). La requête à planifier est
`curl -sN -H "<header>: <token>" "<url>"` : le jeton n'est jamais dans l'URL.

### `POST /system/smtp-test` — administrateur

`to` (facultatif, l'adresse de l'administrateur par défaut) : envoie un email de test avec
le serveur SMTP de la configuration, dans la langue de l'utilisateur de cette adresse (sinon
celle de l'administrateur). Renvoie `{"sent": true, "to": "…"}` ; `400` sans
serveur SMTP configuré ou sans destinataire, `502` si le serveur est injoignable ou refuse
l'email (sa réponse est dans le message d'erreur).

### `POST /system/sync-token` — administrateur

Écrit un nouveau `sync_token` aléatoire dans la configuration et le renvoie : `{"token": "…"}`.
Les tâches planifiées doivent utiliser le nouveau.

## Assistant d'installation

Tant que `data/conf/prod.ini` n'existe pas, l'API ne répond qu'à ces routes (toutes les autres
renvoient `503` avec `"setup": true`) :

| Route | Rôle |
|---|---|
| `GET /setup` | État de l'assistant : `codeRequired`, `dbPasswordFromEnvironment` et valeurs par défaut (`db_host`, `db_port`, `db_name`, `db_user`, `admin_user`, `language`) issues de l'environnement Docker |
| `POST /setup/database` | Teste la connexion (`db_host`, `db_port`, `db_name`, `db_user`, `db_password`) et décrit l'installation : `state` (`none` = base vide, `current` = à jour, `outdated` = migrations à appliquer), `version`, `pending`, `hasAdmin` |
| `POST /setup/install` | Crée ou migre le schéma, crée le premier administrateur s'il n'y en a pas (`admin_user`, `admin_password` de 8 caractères minimum, `admin_email`, `language`) et écrit la configuration ; `409` sans `backup_confirmed` quand des migrations sont à appliquer. Sur une base vide, `starter_categories` (facultatif) ajoute les catégories et règles de `sql/starter.json`, et `starter_insights` ses insights |
| `GET /health` | `{"status":"setup","version":"…","min_mobile_version":"…"}` |

Depuis Internet, ou si `data/conf/setup.code` existe, les requêtes `POST` exigent le paramètre
`code` (code d'installation), sinon `403`. Voir [Sécurité](securite.md#assistant-dinstallation).

## Spécification OpenAPI

`swagger/swagger.php` génère une spécification OpenAPI 3 par réflexion sur les
attributs `#[ApiRoute]` ; `swagger/index.html` l'affiche avec Swagger UI, aussi dans le
portail (**Administration → API**), authentifié avec la session de l'administrateur. Les
chemins de la spécification commencent par `/api` (serveur `/`) : **Try it out** appelle
`/api/...` sur le serveur qui sert Swagger UI. `swagger/carbure.css` fonce les couleurs du
thème clair de Swagger UI (contraste WCAG AA) ; son thème sombre suit celui du portail, ou
la préférence du système quand Swagger UI est ouvert seul.
