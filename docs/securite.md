---
title: "Sécurité : authentification, données bancaires et image Docker"
description: "Comment Carbure protège les données du foyer : mots de passe, JWT, blocage des connexions, identifiants bancaires gardés par woob et analyse de l'image Docker."
icon: material/shield-lock-outline
---

# Sécurité

## Authentification

- `POST /user/login` vérifie le mot de passe et renvoie un **JWT HS256** signé avec
  `jwtsecret`, valable **30 jours** (`sub` = identifiant de l'utilisateur).
- Les autres routes exigent `Authorization: Bearer <token>` ; la signature est comparée
  en temps constant et l'expiration est contrôlée.
- Les mots de passe sont hachés avec `password_hash()` (bcrypt). Les anciens hachages
  SHA-256 salés sont vérifiés une dernière fois puis remplacés à la connexion.

## Autorisations

| Rôle | Droits |
|---|---|
| Utilisateur | Données du foyer (comptes, transactions, budgets, insights, tendances), pointage et catégorie des transactions, montants des budgets, synchronisation par l'API, son profil, ses appareils, ses jetons d'accès MCP |
| Administrateur (`users.is_admin = 1`) | En plus : règles de catégorisation, catégories, comptes bancaires suivis (et leur synchronisation depuis le portail), insights (requêtes SQL), activation du serveur MCP, mise à jour de la base de données, utilisateurs (profil, déblocage) |

Les routes réservées renvoient `403` ; le portail masque les onglets correspondants.

Après **5 tentatives de connexion échouées** d'affilée, le compte est **bloqué 24 heures**
(`423`, dès le 5e échec : la mire de connexion l'indique aussitôt), même avec le bon mot
de passe ; une connexion réussie remet le compteur à zéro. Un
administrateur le débloque depuis l'onglet **Utilisateurs** (`POST /user/unlock`).

Un administrateur ne peut pas supprimer son propre compte ; un utilisateur propriétaire
de transactions ne peut pas être supprimé ; le dernier administrateur ne peut pas perdre
son rôle.

## En-têtes HTTP

| En-tête | API (PHP, toute installation) | Portail (nginx) |
|---|---|---|
| `Content-Security-Policy` | `default-src 'none'; frame-ancestors 'none'` | ressources du serveur uniquement ; `'unsafe-eval'` (Vue compile les modèles dans le navigateur) et styles en ligne |
| `X-Content-Type-Options` | `nosniff` | `nosniff` |
| `X-Frame-Options` | `DENY` | `DENY` |
| `Referrer-Policy` | `no-referrer` | `no-referrer` |
| `Cache-Control` | `no-store` (données bancaires) | — |
| `Cross-Origin-Resource-Policy` | `same-origin` | — |
| `Permissions-Policy`, `Cross-Origin-Opener-Policy` | — | caméra, micro, géolocalisation, paiement, USB désactivés ; `same-origin` |

Les en-têtes de l'API sont posés par PHP (`Webservice::securityHeaders`, et l'assistant
d'installation), quel que soit le serveur web. Ceux du portail et de Swagger UI sont posés
par nginx (`docker/nginx.conf`) ; Carbure ne fournit pas de `.htaccess`.

**Aucun CDN externe** : le portail et Swagger UI ne chargent rien depuis un autre site.
Vue, CodeMirror, les polices (`portal/vendor/`) et Swagger UI (`swagger/vendor/`) sont
servis avec Carbure ; leurs versions et licences sont listées dans le `README.md` de chaque
dossier. Leur CSP n'autorise donc que le serveur lui-même (`'self'`, plus `data:` pour les
images) pour les scripts, styles, polices, images et appels réseau : Swagger UI n'a pas de
script en ligne ni de badge de validation externe. Un test (`tests/portal/localAssets.test.mjs`)
échoue si une page ou une feuille de style charge une ressource externe, ou si la CSP
autorise un autre site. Le site de documentation (carbure.org) héberge lui aussi ses polices,
téléchargées à la construction (plugin `privacy` de Material for MkDocs).

L'API ne révèle pas la version de PHP (`X-Powered-By` retiré) et nginx pas la sienne
(`server_tokens off`). **HSTS** est à activer sur le proxy HTTPS placé devant Carbure (proxy
inversé du NAS, Traefik, Caddy…), le conteneur servant du HTTP. Sur un serveur nginx sans
Docker, reprendre les en-têtes de `docker/nginx.conf`.

## Assistant d'installation

Tant que `data/conf/prod.ini` n'existe pas, l'API ne répond qu'à `/api/setup` (le reste renvoie
`503`). Depuis le réseau local, l'assistant est accessible sans code, comme la plupart des
applications auto-hébergées ; depuis Internet (adresse publique), ou dès que le fichier
`data/conf/setup.code` existe, chaque étape exige le **code d'installation** de ce fichier, aussi
écrit dans les journaux du serveur (`docker logs`). Une erreur de code est ralentie. Le mot
de passe de la base fourni par Docker (`DB_PASSWORD`) n'est utilisé que pour la base de
l'environnement, jamais pour un autre serveur saisi dans l'assistant. Une fois la
configuration écrite, l'assistant disparaît et le code est supprimé.

!!! warning "Proxy inversé"
    L'adresse prise en compte est celle qui se connecte au serveur PHP (`REMOTE_ADDR`).
    Derrière un proxy inversé, c'est l'adresse du proxy, donc une adresse locale : le code
    n'est alors pas demandé. Installez Carbure avant de l'exposer sur Internet, ou créez
    `data/conf/setup.code` (Docker : `/data/conf/setup.code`) avec un code de votre choix pour
    l'imposer.

Les mises à jour de schéma ultérieures se font sans l'assistant : migrations appliquées
au démarrage du conteneur Docker ou par un administrateur depuis le portail
(`POST /system/migrate`).

## Insights (SQL stocké)

Les insights sont des requêtes SQL stockées en base et exécutées par le serveur. Seul un
administrateur peut les gérer, et chaque requête est encadrée :

- à l'enregistrement : un unique `SELECT`, sans `;` ni commentaire, sans mot-clé d'écriture,
  d'administration, de fichier (`INTO OUTFILE`, `LOAD_FILE`) ou de temporisation (`SLEEP`,
  `BENCHMARK`), sans accès aux tables `users`, `api_tokens`, `devices` ni aux schémas système ;
  la requête est testée avant d'être enregistrée ;
- à l'exécution : dans une **transaction en lecture seule** (`START TRANSACTION READ ONLY`),
  et une requête en échec donne `0` sans bloquer les autres.

## Jetons d'accès (MCP)

Le serveur MCP (`/api/mcp`) est **désactivé par défaut** ; un administrateur l'active dans
l'onglet **Agent**. Il accepte des jetons d'accès créés par chaque utilisateur dans cet
onglet : 192 bits aléatoires, préfixe `cbt_`, **seule l'empreinte SHA-256 est stockée**,
durée de validité au choix (30, 90 ou 365 jours, ou sans expiration), révocables, avec la
date de dernière utilisation. Ils ne donnent accès qu'aux outils en
lecture seule du serveur MCP, pas au reste de l'API. Le serveur refuse les requêtes
portant un en-tête `Origin` d'un autre site que Carbure ou les clients web de Claude
(protection contre le DNS rebinding).

Les agents qui ne prennent qu'une URL (Claude web, Desktop, mobile) obtiennent un tel jeton
par **OAuth 2.1** : l'utilisateur se connecte au portail et autorise explicitement l'agent ;
**PKCE `S256` obligatoire**, codes d'autorisation à usage unique valables 5 minutes, URI de
retour enregistrées et comparées à l'identique (HTTPS, ou HTTP sur `localhost`), jetons de
rafraîchissement à rotation, seules les empreintes SHA-256 des codes, jetons et secrets
clients sont stockées. L'enregistrement des clients est ouvert (comme le prévoit la
spécification MCP) mais limité à 100 clients, les clients inutilisés étant purgés après
un jour ; tout est refusé quand le serveur MCP est désactivé.

## Routes publiques

Seules ces routes ne demandent pas de JWT (un test unitaire le vérifie) :

- `POST /user/login` ;
- `GET /bank/sync`, qui exige **soit** un JWT, **soit** le `sync_token` de la
  configuration (en-tête `X-Sync-Token` de préférence, ou `?token=`). Sans `sync_token`
  configuré, seul un utilisateur connecté peut lancer la synchronisation ;
- `POST /mcp` et `GET /mcp` (serveur MCP), qui exigent un jeton d'accès ou un JWT ;
- les routes OAuth des agents IA (métadonnées `/.well-known/oauth-*`, enregistrement,
  autorisation et jetons), désactivées avec le serveur MCP ; l'autorisation elle-même
  (`POST /oauth/approve`) exige l'utilisateur connecté au portail ;
- `GET /health` (supervision), qui ne renvoie que l'état de la base et la version du schéma.

## Données sensibles

- Les logs masquent les champs `password` et `token` des requêtes, les secrets OAuth
  (`code`, `code_verifier`, `refresh_token`, `access_token`, `client_secret`), et le
  paramètre `token=` des URL (synchronisation, MCP). Le corps des réponses du serveur MCP et
  d'OAuth n'est pas journalisé.
- Les messages d'erreur ne renvoient ni hachage ni secret ; l'`uid` permet de retrouver
  le détail dans les logs serveur.
- **Format des logs** : une entrée par ligne JSON (JSON Lines), avec l'heure ISO 8601
  (millisecondes, fuseau), le niveau, l'identifiant de la requête (`uid`, repris d'un en-tête
  `X-Request-Id` valide), l'**adresse IP du client**, l'utilisateur, la méthode et le chemin
  (jetons de l'URL masqués), l'appelant, le message et son contexte. Chaque requête se
  termine par une ligne avec son statut HTTP et sa durée (INFO, WARNING pour 4xx, ERROR
  pour 5xx). Un message ne peut pas simuler une autre entrée : ses retours à la ligne
  sont échappés.
- **Adresse du client** : derrière un proxy de confiance (adresse privée ou locale : nginx
  de l'image, proxy inversé du NAS), c'est la dernière adresse publique de
  `X-Forwarded-For` (ou `X-Real-IP`) ; sinon l'adresse de connexion, car ces en-têtes
  peuvent alors être forgés.
- Une adresse IP est une **donnée personnelle** : les fichiers de log sont supprimés après
  `log_retention_days` jours (90 dans une nouvelle installation, voir
  [Configuration](configuration.md)).
- `data/` : `conf/prod.ini`, `conf/certs/*.p8`, `logs/`, `woob/` (identifiants bancaires woob)
  sont exclus du dépôt Git.

## Recommandations de déploiement

!!! danger "Secret JWT"
    L'assistant d'installation génère un `jwtsecret` aléatoire ; si la configuration
    contient encore la valeur d'exemple, un bandeau propose aux administrateurs de le
    renouveler en un clic (`POST /system/jwt-secret`). Une valeur faible ou la valeur
    d'exemple permet de forger un jeton pour n'importe quel utilisateur. Changer le
    secret invalide toutes les sessions (app iOS et portail).

- Servir l'API et le portail **uniquement en HTTPS** (JWT et mots de passe transitent
  dans les requêtes ; la copie du token dans le portail exige HTTPS).
- Ne pas exposer `data/`, `conf/`, `sql/`, `tests/` ni `tools/` par le serveur
  web.
- Générer un `sync_token` long et le passer en en-tête plutôt qu'en query string (les
  URL peuvent être journalisées).
- Restreindre l'utilisateur MySQL à la base `carbure`.
- Surveiller `data/logs/carbure_AAAAMMJJ.log` (erreurs de synchronisation, accès refusés : les lignes WARNING de fin de requête en 401 ou 403, avec l'IP du client). Au format JSON Lines, le fichier se lit aussi avec `jq` ou un collecteur de logs.

## Analyses de sécurité

Le workflow **Security** (badge du README) regroupe les analyses de sécurité :

- **gitleaks** cherche des secrets (mots de passe, jetons, clés) dans tout l'historique
  git, à chaque pull request et à chaque push sur `main` ;
- **Trivy** analyse les dépendances du dépôt (fichiers de verrouillage npm et pip) aux
  mêmes moments ;
- **Trivy** analyse **chaque lundi** l'image publiée sur Docker Hub
  (`ltoinel/carbure:latest`) : de nouvelles vulnérabilités sont publiées sans que Carbure
  change. Un échec signale qu'une nouvelle release (image reconstruite) est nécessaire.
  L'analyse se lance aussi à la main (**Actions → Security → Run workflow**).

Une vulnérabilité **critique pour laquelle un correctif existe** fait échouer l'analyse ;
les rapports complets (critique, haute, moyenne) sont publiés dans l'onglet **Security**
du dépôt.

## Sécurité de l'image Docker

Les modules bancaires de woob (code Python qui lit les identifiants) sont téléchargés
depuis le dépôt de woob : l'image contient `gpgv`, avec lequel woob vérifie la signature
de chaque module installé ou mis à jour et refuse un module dont la signature est
invalide.

La clé du dépôt de woob (`https://updates.woob.tech/3/main/`) n'est approuvée qu'une
fois : les clés suivantes sont vérifiées par woob avec la précédente. Mais **toute
commande woob qui rafraîchit les dépôts alors qu'aucune clé n'est approuvée lui fait
confiance d'elle-même** : sans terminal, woob affiche la question puis
`*** ASSUMING YES ***`. Carbure ne lance donc pas woob tant qu'un **administrateur** n'a
pas approuvé cette clé, depuis l'onglet **Comptes** (bouton **Mettre à jour les modules
bancaires**) : le portail affiche le dépôt et l'empreinte SHA-1 de sa clé, à comparer avec
celle publiée ou annoncée par les mainteneurs de woob, puis **Faire confiance et mettre à
jour**. Carbure télécharge lui-même la clé juste avant : woob n'est lancé que si son
empreinte est exactement celle confirmée ; après la mise à jour, la clé que woob a
conservée (`/data/woob/.local/share/woob/keyrings/`) doit aussi être celle-là, sinon elle
est supprimée et la mise à jour échoue.

Tant que le dépôt n'est pas approuvé, Carbure refuse aussi (`409`, « Trust the woob
repository first ») :

- **toute commande woob** sur une installation neuve : au premier lancement (pas encore de
  `~/.config/woob/sources.list`), n'importe quelle commande de woob crée la liste des
  dépôts et les rafraîchit d'abord, faisant confiance à la clé d'elle-même ; la mise à
  jour approuvée par l'administrateur est alors le premier lancement de woob ;
- d'**installer un module bancaire** (connecter une banque dont le module n'est pas encore
  installé) : woob vérifie sa signature avec la clé du dépôt ;
- l'option `--auto-update` (`woob_auto_update`) n'est passée à woob qu'une fois le dépôt
  approuvé : après le plantage d'un module, elle rafraîchit les dépôts.

Approuvez donc le dépôt avant de connecter une banque. Une commande woob lancée à la main
dans le conteneur (`woob config update`, ou n'importe laquelle au premier lancement) ferait
confiance à la clé d'elle-même.

Une fois le dépôt approuvé, le conteneur lance aussi `woob config update` à chaque
démarrage (dépôts, clé et modules installés), sans jamais répondre à une question de
woob ; tant qu'il ne l'est pas, il l'indique dans ses logs et ne met rien à jour. Un échec
de la mise à jour est écrit dans les logs du conteneur. Une commande woob lancée à la main dans le
conteneur doit l'être en `www-data` (`docker exec -u www-data …`) : lancée en root, elle
créerait dans `/data/woob` des fichiers que Carbure ne pourrait plus modifier (le
redémarrage du conteneur les lui rend).

Chaque build de la CI analyse aussi l'image construite :

- **hadolint** vérifie les bonnes pratiques du `docker/Dockerfile` ;
- **Trivy** recherche les vulnérabilités connues des paquets Alpine et des dépendances PHP
  et les secrets restés dans l'image. woob et ses dépendances Python (`/opt/woob`) ne sont
  pas analysés : ils suivent les versions de woob (`WOOB_VERSION`). Une vulnérabilité
  **critique pour laquelle un correctif existe** fait échouer le build (et donc la
  release) ; le rapport complet (critique, haute, moyenne) est publié dans l'onglet
  **Security** du dépôt.

Une vulnérabilité sans impact sur Carbure peut être acceptée dans `.trivyignore`, avec sa
justification. Les images publiées embarquent leur SBOM et leur provenance de build
(`docker buildx imagetools inspect ltoinel/carbure:latest --format '{{ json .SBOM }}'`).
Pour analyser une image localement :

```bash
docker run --rm -v /var/run/docker.sock:/var/run/docker.sock aquasec/trivy image ltoinel/carbure:latest
```

### Vérifier l'origine de l'image

Depuis la version 1.4.0, le workflow **Release** publie aussi une **attestation de
provenance** signée par GitHub (Sigstore) pour l'image poussée sur Docker Hub : elle prouve
que l'image (identifiée par son empreinte `sha256`) a été construite par le workflow
`release.yml` du dépôt `ltoinel/Carbure`, à partir du tag indiqué. Avant de mettre à jour le
NAS, on la vérifie avec la [CLI GitHub](https://cli.github.com/) (`gh auth login` au
préalable), depuis le NAS ou n'importe quel poste :

```bash
gh attestation verify oci://docker.io/ltoinel/carbure:1.4.0 --owner ltoinel
# Plus strict : le dépôt et le workflow qui ont signé
gh attestation verify oci://docker.io/ltoinel/carbure:1.4.0 \
  --repo ltoinel/Carbure \
  --signer-workflow ltoinel/Carbure/.github/workflows/release.yml
```

La commande échoue si l'image n'a pas d'attestation valide (image modifiée, poussée par un
autre moyen que le workflow, ou version antérieure à 1.4.0). L'attestation est aussi
visible dans l'onglet **Actions → Attestations** du dépôt. Pour figer exactement l'image
vérifiée, la référencer ensuite par son empreinte (`ltoinel/carbure@sha256:…`, affichée
par la commande) plutôt que par son tag.

### Chaîne de build

Les workflows GitHub Actions référencent chaque action par l'**empreinte complète de son
commit** (et non par un tag, qui peut être déplacé), tenue à jour par Dependabot avec un
délai de 7 jours après chaque nouvelle version ; `actions/checkout` ne conserve pas le
jeton git dans le dépôt cloné, chaque job a une durée maximale, et le titre de chaque pull
request (le message du commit sur `main`) est vérifié.

## Signaler une vulnérabilité

Merci de ne pas ouvrir d'issue publique : contacter le mainteneur via GitHub
(<https://github.com/ltoinel>).
