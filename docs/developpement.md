---
title: "Développement : contribuer à Carbure"
description: "Contribuer à Carbure : organisation du dépôt, environnement Docker, tests PHPUnit et Playwright, analyse PHPStan, conventions et intégration continue."
icon: material/code-braces
---

# Développement

## Organisation du dépôt

| Chemin | Contenu |
|---|---|
| `src/lib/` | Socle technique (configuration, base, logs, JWT, routage, woob, APNs, assistant d'installation, migrations, réglages) |
| `src/resources/` | Ressources de l'API |
| `portal/` | Portail web Vue 3 |
| `swagger/` | Générateur OpenAPI et Swagger UI |
| `sql/` | Schéma et migrations |
| `tests/unit/`, `tests/integration/` | Tests PHPUnit |
| `tests/portal/` | Tests unitaires du portail (`node:test`) |
| `tests/e2e/` | Tests de bout en bout du portail (Playwright) |
| `tools/migrate.php` | Migrations du schéma (`--status`, `--dry-run`, `--baseline`) |
| `tools/phpunit.phar`, `tools/coverage-check.php` | PHPUnit 11 et contrôle du seuil de couverture |
| `composer.json`, `phpstan.neon`, `tools/phpstan/` | Outils de développement (PHPStan, installé par Composer dans `vendor/`) et règle de typage de Carbure ; l'API elle-même n'a aucune dépendance |
| `docker/` | `Dockerfile`, `docker-compose.yml` (avec MariaDB), configuration nginx et PHP-FPM, `entrypoint.sh` |
| `data/` (non versionné) | Données de l'instance : `conf/` (`prod.ini`, `certs/`), `logs/`, `cache/`, `woob/` (banques et identifiants) |
| `VERSION` | Version de Carbure (`dev` dans le dépôt, écrite par le build de release) |
| `docs/`, `mkdocs.yml` | Cette documentation |

Règles détaillées pour les contributeurs et agents de code : `AGENTS.md`.
Tâches ouvertes : `TODO.md`.

## Démarrer en local

```bash
./start.sh            # http://localhost:8000/portal/  (admin / admin-password, marie / marie-password)
./start.sh --reset    # repartir des données d'exemple (aussi après une modification de sql/carbure.sql)
./start.sh --fresh    # repartir d'une base et d'une configuration vides : assistant d'installation
./start.sh --build    # reconstruire l'image (modification de docker/ ou des extensions PHP)
./start.sh --fake-woob  # woob bouchonné : synchronisation des comptes d'exemple sans banque
./start.sh --stop     # arrêter (la base et /data sont conservés)
./start.sh --clean    # arrêter et supprimer la base et /data
./start.sh --help     # aide
```

`start.sh` reproduit l'architecture de production : il lance `docker/docker-compose.yml`
(le compose de production) avec la surcharge `docker/docker-compose.dev.yml`. Mêmes
conteneurs que la cible : l'**image Docker de Carbure** (`docker/Dockerfile` : nginx,
PHP-FPM, woob, entrypoint qui applique les migrations) et **MariaDB 13**, avec un volume
`/data` et un `/data/conf/prod.ini`, comme une instance installée. La surcharge ne change
que :

- le code (`src/`, `portal/`, `swagger/`, `sql/`, `tools/`), **monté** depuis le dépôt au
  lieu d'être copié dans l'image : une modification est prise en compte à la requête
  suivante, sans redémarrer ni reconstruire ;
- les ports, qui n'écoutent que sur `127.0.0.1` ; la synchronisation planifiée, désactivée ;
- la base, `carbure_dev`, et `/data`, un volume Docker (le dossier `data/` local n'est pas
  monté).

Il n'y a qu'une image applicative, celle de production : on développe sur ce qui sera
livré. Reconstruire l'image (`--build`) n'est utile qu'après une modification de
`docker/` (Dockerfile, nginx, PHP).

- Au premier lancement, l'image est construite (quelques minutes pour woob) et la base
  reçoit le jeu de données des tests de bout en bout (`tests/e2e/server/seed.php`) : un
  foyer avec trois mois de transactions, des budgets, des règles et deux comptes.
- `prod.ini` est réécrit à chaque démarrage (`log_level=debug`, base `carbure_dev`).
  woob est le vrai par défaut ; `--fake-woob` le remplace par le faux woob des tests
  (`tests/fixtures/fake-woob.php`), qui répond à la place des banques : les comptes
  d'exemple se synchronisent (`fail@bank` échoue volontairement), l'ajout d'une banque et
  la découverte des comptes fonctionnent sans identifiants. Les options se combinent
  (`./start.sh --reset --fake-woob`).
- `--fresh` reproduit une première installation : la base et `/data` sont vidés, aucun
  `prod.ini` n'est écrit et aucune donnée d'exemple n'est chargée ; le portail affiche
  l'**assistant d'installation**, pré-rempli avec la base de dev (pack de départ
  compris). Les démarrages suivants gardent la configuration et les données créées par
  l'assistant (marqueur `/data/conf/.wizard`), jusqu'à `--reset` ou `--clean`.
- `PORT` (8000) et `DB_PORT` (3308, accès à MariaDB depuis l'hôte : `carbure` / `dev`)
  changent les ports, qui n'écoutent que sur `127.0.0.1`. Logs : onglet Logs du portail,
  ou `docker compose -f docker/docker-compose.yml -f docker/docker-compose.dev.yml exec carbure ls /data/logs`.

## Ajouter une route

```php
final class Transaction {

    /**
     * Search transactions by label.
     *
     * @param string $query The text to find in the label
     * @param int    $limit The maximum number of transactions
     * @return array The list of transactions
     */
    #[ApiRoute('/transaction/search', method: 'GET')]
    public static function search($query, $limit = 100)
    {
        // ...
    }
}
```

- La méthode doit être **publique et statique**, dans une classe de `src/resources/`
  (nom de fichier = nom de classe).
- Les clés de la requête sont passées **par nom** : le nom des paramètres fait partie
  du contrat d'API.
- `public: true` désactive le contrôle du JWT ; `stream: true` produit une réponse SSE ;
  `raw: true` renvoie tel quel le texte retourné par la méthode (serveur MCP). Un test
  unitaire vérifie la liste des routes publiques.
- Route d'administration : appeler `User::requireAdmin()` en premier (`403` sinon), et
  ajouter l'onglet ou l'action du portail à la liste réservée aux administrateurs.
- Erreur client : `throw new Error("message", 400)` ; erreur serveur : `Exception`.
- Utilisateur courant : `Jwt::getUserIdFromToken()`.

!!! warning "Compatibilité"
    L'API est utilisée par l'app iOS, mise à jour indépendamment du serveur. N'ajouter
    que des routes ou des paramètres facultatifs ; ne jamais renommer ni supprimer.

## Types

Chaque paramètre, valeur de retour et propriété du code PHP a un type natif (vérifié par
PHP à l'exécution) ; les tableaux sont détaillés dans la PHPDoc (`array<string, mixed>`).
PHPStan le contrôle (niveau 6 et règle `tools/phpstan/NativeTypesRule.php`) :

```bash
composer install     # une fois : PHPStan dans vendor/ (outil de développement)
composer phpstan
```

Pas de `declare(strict_types=1)` : les paramètres des requêtes arrivent en chaînes
(query string) et sont convertis. Un indicateur de route n'est jamais typé `bool` seul
(`"false"` deviendrait vrai).

## Complexité

La complexité cyclomatique de chaque fonction ou méthode (1 + une par décision : `if`,
`elseif`, boucle, `case`, `catch`, ternaire, `&&`/`||`, bras de `match`) est limitée à
**10** (règle `tools/phpstan/CyclomaticComplexityRule.php`, seuil dans `phpstan.neon`),
contrôlée avec les types par `composer phpstan`, en pre-commit et en CI. Une méthode qui
grossit se découpe en petites méthodes privées.

## Tests

```bash
# Unitaires : sans dépendance externe
php tools/phpunit.phar --testsuite unit

# Unitaires + intégration : MariaDB/MySQL requis
DB_HOST=127.0.0.1 DB_USER=root DB_PASSWORD=root php tools/phpunit.phar
```

- `APP_ENV=testing` (défini dans `phpunit.xml`) charge `conf/testing.ini`.
- Les tests d'intégration recréent la base `carbure_test` depuis `sql/carbure.sql`
  (`tests/bootstrap.php`).
- Objectif : **≥ 90 % de couverture** du code de `src/`, mesurée en CI sur l'ensemble
  des suites.

### Tests unitaires du portail

```bash
node --test 'tests/portal/*.test.mjs'
```

Les modules du portail qui ne dépendent pas du DOM (`utils/`, `services/apiService.js`
avec un faux `fetch`, `stores/budgetStore.js`, les méthodes des mixins de `modules/`)
sont testés avec le lanceur de tests de Node, sans dépendance. `tests/portal/setup.mjs`
fournit les quelques objets du navigateur dont ils ont besoin (`window`, `CSS`, `Vue`).

### Tests de bout en bout du portail

Les tests Playwright de `tests/e2e/specs/` pilotent le portail dans Chromium, sur la
**vraie API** et une base MariaDB `carbure_e2e` recréée avant chaque test : ils vérifient
aussi que le portail et l'API s'accordent (routes, paramètres, réponses).

```bash
cd tests/e2e
npm ci && npx playwright install chromium   # une seule fois
E2E_DB_HOST=127.0.0.1 E2E_DB_PASSWORD=root npx playwright test
npx playwright test specs/budget.spec.js     # un seul fichier
npx playwright test --ui                     # mode interactif
npx playwright show-report                   # rapport HTML de la dernière exécution
```

- Playwright démarre le serveur intégré de PHP (`php -S`) avec le routeur de test
  `tests/e2e/server/router.php` (`APP_ENV=e2e`, `conf/e2e.ini`) : il sert le portail,
  l'API, et `/api/__e2e/reset`, qui recrée la base avec le jeu de données de
  `tests/e2e/server/seed.php` (foyer, catégories, budgets, règles, deux comptes). Ce
  routeur n'est jamais servi par nginx et refuse de répondre hors `APP_ENV=e2e`.
- Il faut un PHP avec `mysqli` (`PHP_BIN` pour en choisir un) et une base MariaDB/MySQL :
  `E2E_DB_HOST`, `E2E_DB_PORT` (3306), `E2E_DB_USER` (root), `E2E_DB_PASSWORD`. Par exemple
  `docker run -d -e MARIADB_ROOT_PASSWORD=root -p 3306:3306 mariadb:10.11`.
- woob est remplacé par `tests/fixtures/fake-woob.php`, les logs vont dans
  `tests/e2e/.data/logs/`.
- Les tests s'exécutent l'un après l'autre (une seule base). Les textes attendus sont lus
  dans `portal/i18n.js` (`tr()` de `tests/e2e/fixtures.js`) : modifier un libellé ne casse
  pas les tests.
- L'assistant d'installation et les bandeaux d'administration simulent les réponses de
  l'API (`page.route`), le serveur de test étant déjà installé et à jour.

## Lint

```bash
find src tests swagger -name '*.php' -print0 | xargs -0 -n1 php -l
for f in $(find portal -name '*.js'); do cp "$f" /tmp/check.mjs && node --check /tmp/check.mjs; done
```

Les fichiers du portail sont des modules ES : `node --check` doit les lire en `.mjs`.

## Hooks Git

```bash
git config core.hooksPath .githooks
```

| Hook | Vérification |
|---|---|
| `pre-commit` | `php -l` et `node --check` sur les fichiers indexés, PHPStan si un fichier PHP est indexé (`composer install` requis) |
| `pre-push` | Tests unitaires PHP et du portail |

## Intégration et livraison continues

| Workflow | Déclencheur | Étapes |
|---|---|---|
| CI | Pull request, push sur `main` | Lint PHP/JS/CSS, tests unitaires du portail, tests unitaires et d'intégration (MariaDB), couverture ≥ 90 %, tests de bout en bout du portail (Playwright, rapport en artefact en cas d'échec) ; image Docker : hadolint, build, scan Trivy, démarrage avec `docker compose`, installation par l'assistant et test de fumée |
| Release | Push d'un tag (`v1.2.0` ou `1.2.0`), ou lancement manuel avec le tag | CI, image Docker `amd64`/`arm64` publiée sur Docker Hub (`ltoinel/carbure`) (version passée par `CARBURE_VERSION`), avec SBOM et provenance, et attestation de provenance signée par GitHub ; puis création de la release avec l'archive de l'application (`src`, `portal`, `swagger`, `sql`, `tools/migrate.php`, `VERSION` écrit depuis le tag) et les notes générées |
| Security | Pull request, push sur `main`, chaque lundi, lancement manuel | Scan de secrets de l'historique (gitleaks), vulnérabilités des dépendances du dépôt (Trivy) ; chaque lundi et à la demande, vulnérabilités de l'image publiée sur Docker Hub (Trivy). Rapports dans l'onglet Security |
| Docs | Push sur `main`, pull request (documentation modifiée) | Construction MkDocs ; sur `main`, déploiement GitHub Pages |
| PR title | Pull request (ouverture, modification, push) | Titre conforme à Conventional Commits (il devient le commit de `main`) |

Les actions sont référencées par l'empreinte de leur commit (`uses: owner/action@<sha> # vX.Y.Z`),
mises à jour par Dependabot : pour en ajouter une, résoudre l'empreinte du tag
(`gh api repos/<owner>/<action>/commits/<tag> --jq .sha`). Chaque job a un
`timeout-minutes` ; un nouveau push sur une pull request annule son exécution précédente,
jamais celles de `main`.

Publier une version : pousser un tag sur `main` (ne **pas** créer la release dans
l'interface de GitHub) :

```bash
git switch main && git pull
git tag v1.2.0 && git push origin v1.2.0
```

Le workflow lance la CI, publie l'image Docker Hub `ltoinel/carbure` (`1.2.0`, `1.2` et
`latest`), puis crée la release avec ses archives et les notes générées (groupées par
étiquette, `.github/release.yml`). Un tag avec un suffixe (`v1.2.0-rc.1`) donne une
pré-release, sans le tag `latest`. Les releases du dépôt sont **immuables** : aucun fichier
ne peut être ajouté une fois la release publiée, d'où cet ordre (le workflow crée un
brouillon, y joint les archives, puis le publie). L'image reçoit aussi une attestation de
provenance (voir [Sécurité](securite.md#verifier-lorigine-de-limage)).

**Actions → Release → Run workflow** (champ `tag`) reconstruit l'image Docker d'un tag
existant ; sa release, immuable, n'est pas modifiée. Le tag doit être une version
sémantique existante (`v1.2.0`, `1.2.0`, `v1.2.0-rc.1`). `latest` n'est posé que sur la plus
haute version publiée et `1.2` que sur la plus haute `1.2.x` : reconstruire une ancienne
version ne les fait pas reculer.

La publication sur Docker Hub (`ltoinel/carbure`) utilise les secrets du dépôt
`DOCKERHUB_USERNAME` et `DOCKERHUB_TOKEN` (jeton d'accès Docker Hub en lecture/écriture, dans
**Settings → Secrets and variables → Actions**).

## Migrations de base

Le schéma est versionné : la table `schema_migrations` liste les migrations appliquées, la
version du schéma est la dernière. `tools/migrate.php` applique celles qui manquent, dans
l'ordre de leur nom ; le conteneur Docker le lance à chaque démarrage, l'assistant
d'installation migre une base existante, et un administrateur peut les appliquer depuis le
bandeau du portail (`POST /api/system/migrate`).

1. Créer `sql/migrations/AAAA-MM-JJ_description.sql`.
2. Y déclarer **obligatoirement** une ligne `-- applied-if: <requête>` qui renvoie un nombre
   non nul quand la migration est déjà en place (par exemple une colonne présente dans
   `information_schema.COLUMNS`) : une base créée depuis `sql/carbure.sql`, ou migrée à la
   main, est alors reconnue au lieu d'échouer. Un test le vérifie.
3. Reporter la modification dans `sql/carbure.sql` (installations neuves et tests) et
   ajouter la version de la migration à la liste `schema_migrations` en fin de fichier.
4. Tant que la migration peut ne pas être appliquée (instance hors Docker pas encore mise à
   jour), le code qui en dépend doit rester tolérant (par exemple en
   interceptant l'erreur de la requête), ou n'être déployé qu'après `php tools/migrate.php` ; le noter dans
   `TODO.md`.

Quand toutes les bases connues ont passé un ensemble de migrations, celles-ci peuvent être
retirées : `sql/carbure.sql` les contient déjà (c'est ce qui a été fait pour la version 1.0,
voir `sql/migrations/README.md`).

## Documentation

```bash
pip install -r docs/requirements.txt
mkdocs serve      # http://127.0.0.1:8000
```

## Conventions

- PHP : PHPDoc sur chaque méthode, requêtes SQL préparées uniquement.
- Portail : textes via `t('clé')`, clés ajoutées en `fr` **et** en `en` dans `i18n.js`.
- Fins de ligne LF (`.gitattributes`), messages de commit en anglais.
