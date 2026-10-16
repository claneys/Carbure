---
title: "Portail web : transactions, budgets et administration"
description: "Les écrans du portail web de Carbure : transactions du mois, recherche, import de relevés, budgets, flux, tendances, insights, comptes, règles et utilisateurs."
icon: material/monitor-dashboard
---

# Portail web

Le portail (`portal/`) est une application Vue 3 servie en fichiers statiques, sous
`https://<serveur>/portal/` (l'adresse racine y redirige). Il utilise la même API que
l'app iOS. Cette page décrit les écrans ; les règles de fonctionnement (calculs, règles,
notifications, droits) sont dans [Fonctionnalités](fonctionnalites.md).

## Installation

Tant que Carbure n'est pas installé, le portail affiche l'**assistant d'installation**,
en trois étapes :

1. **Base de données** : serveur, port, nom de la base, utilisateur et mot de passe, déjà
   remplis avec Docker (laisser le mot de passe vide : celui de `docker/docker-compose.yml` est
   utilisé). Depuis Internet, un **code d'installation** est aussi demandé (journaux du
   conteneur ou fichier `data/conf/setup.code`).
2. **Installation** : l'assistant indique ce qu'il va faire — créer les tables d'une base
   vide, conserver une base Carbure à jour, ou mettre à jour une base Carbure existante
   (après avoir coché « J'ai une sauvegarde de ma base de données »). Il demande
   l'identifiant, le mot de passe (8 caractères minimum), l'e-mail et la langue du
   **compte administrateur** s'il n'en existe pas encore. Sur une base vide, il propose
   aussi, **pour bien démarrer**, des catégories courantes avec leurs règles et des insights
   (deux cases cochées par défaut ; noms dans la langue choisie ; tout reste modifiable).
3. **Terminé** : connexion avec ce compte.

Si le navigateur garde la session d'une installation précédente à la même adresse (Carbure
réinstallé de zéro), elle est fermée et l'assistant s'affiche.

## Connexion et profils

L'écran de connexion demande l'identifiant et le mot de passe : l'API est celle du serveur
qui sert le portail (`/api`, à côté de `/portal/`). Le JWT est conservé dans le navigateur
(`localStorage`) jusqu'à la déconnexion ou l'expiration de la session (30 jours). Après **5 échecs de connexion** d'affilée, le
compte est bloqué 24 heures ; un administrateur peut le débloquer.

Deux profils existent :

| Profil | Onglets |
|---|---|
| **Utilisateur** | Transactions, Budget, Insights, Tendances ; **Agent IA** et **Mon profil** dans le menu utilisateur |
| **Administrateur** | En plus : Règles, Catégories, Comptes, et le menu **Administration** (Utilisateurs, Synchro, Agent IA, Logs, Paramètres) ; ajout et modification des insights ; activation du serveur MCP ; mise à jour de la base de données |

Les pages d'administration (Règles, Catégories, Comptes, Utilisateurs) et les onglets
Insights et Agent IA suivent la même présentation : un en-tête avec le titre et l'aide, et
un bouton rond **+** qui ouvre le formulaire dans une fenêtre modale, qui sert aussi à la
modification.

## Installer le portail comme une application

Le portail peut s'installer comme une **application** : il s'ouvre alors dans sa propre
fenêtre, sans barre d'adresse ni onglets, avec l'icône de Carbure dans le Dock, la barre des
tâches ou l'écran d'accueil.

| Navigateur | Installation |
|---|---|
| **Chrome, Edge** (Windows, macOS, Linux) | icône d'installation à droite de la barre d'adresse, ou menu **⋮** → **Installer Carbure** (Edge : **Applications** → **Installer cette application**) |
| **Safari** (macOS Sonoma et suivants) | menu **Fichier** → **Ajouter au Dock** |
| **Safari** (iPhone, iPad) | bouton **Partager** → **Sur l'écran d'accueil** |
| **Chrome** (Android) | menu **⋮** → **Ajouter à l'écran d'accueil** (ou **Installer l'application**) |

- **HTTPS obligatoire** : les navigateurs ne proposent l'installation que pour un site en
  HTTPS (proxy inversé devant le conteneur, voir [Installation](installation.md)), sauf
  sur `localhost`.
- La fenêtre de l'application est celle du portail : même connexion (la session est
  partagée avec le navigateur), même thème, déconnexion et reconnexion dans la fenêtre. La
  couleur de sa barre de titre suit le thème clair ou sombre. Les liens externes (site de
  Carbure, tickets woob) s'ouvrent dans le navigateur.
- **Pas de mode hors ligne** : le portail n'a pas de *service worker* ; rien n'est gardé en
  cache (ni les données bancaires ni le jeton de session), il faut le serveur pour
  l'utiliser.
- Le manifeste (`portal/manifest.webmanifest`) utilise des adresses relatives : il
  fonctionne aussi quand Carbure est servi sous un sous-chemin. Ses icônes
  (`portal/icons/`) sont tirées de `portal/icon.png`.

## En-tête

- Le titre **Carbure** ramène à l'accueil : transactions du mois en cours.
- En haut à droite, le bouton affiche le **login** de l'utilisateur connecté ; son menu
  donne accès à **Mon profil**, à **Agent IA** (utilisateurs non administrateurs) et à
  **Déconnexion**. Juste avant, l'icône de **thème** passe au thème clair ou sombre (voir
  [Thèmes](#themes-et-preferences-du-systeme)).
- Dans la barre du haut, le sélecteur de **mois** et d'**année** et le bouton rond
  **Actualiser** (icône seule) s'appliquent aux onglets Transactions, Budget et Insights.
  Ils ne proposent que les mois qui ont des transactions, plus le mois en cours ; une autre
  année sélectionne son dernier mois disponible. Les onglets Tendances et Règles ont leurs
  propres réglages.

!!! note "Mise à jour de la base de données"
    Quand une nouvelle version de Carbure modifie la base (migrations en attente), un
    bandeau **Mise à jour de la base de données disponible** s'affiche pour les
    administrateurs. Après avoir sauvegardé la base, le bouton **Mettre à jour** applique
    les migrations (`POST /system/migrate`) ; les données sont conservées. Avec Docker,
    elles sont de toute façon appliquées à chaque démarrage du conteneur.

## Transactions

![Écran Transactions du portail web Carbure](assets/screenshot-transactions.png)

- Statistiques du mois : revenus, dépenses, solde.
- Liste compacte des transactions avec l'icône et la couleur de leur catégorie (celles du
  type d'opération si elles ne sont pas catégorisées), libellé, date et montant.
- **Pointage** : le bouton rond à gauche de chaque transaction la marque comme vérifiée
  (coche verte) ou non vérifiée. Les transactions non vérifiées sont en gras.
- **Filtre** : à côté du nombre de transactions, un interrupteur limite la liste aux
  transactions non vérifiées (avec leur nombre).
- **Transactions récurrentes** : celles retrouvées chaque mois (salaire, loyer,
  abonnements, factures, `GET /transaction/recurring`) portent le badge « Mensuelle ». Un
  second interrupteur n'affiche qu'elles et liste celles qui ne sont pas encore arrivées
  (« Attendues ce mois-ci », avec leur jour habituel et leur montant, « ≈ » s'il varie ;
  « Absentes ce mois-ci » pour un mois passé). Il n'apparaît pas pendant une recherche.
- **Recherche** : le champ de recherche (2 caractères minimum) interroge
  `GET /transaction/search` sur tous les mois et affiche le **total** des transactions
  trouvées ; le filtre s'applique aussi aux résultats. La croix efface la recherche.
- **Catégorisation manuelle** : une transaction sans catégorie propose un sélecteur
  « Catégoriser… » (icônes, couleurs, recherche) ; la transaction est aussi pointée. Pour un administrateur, un bandeau
  propose ensuite de **créer une règle** pour classer automatiquement ce libellé : il ouvre
  l'onglet Règles avec le libellé et la catégorie pré-remplis, le mot-clé pouvant être
  raccourci (dates, numéros de carte…).
- **Détail d'une transaction** : un clic (ou Entrée) sur le libellé ouvre une fenêtre avec
  le montant, le libellé complet, les dates, le type, la carte, la banque (compte synchronisé
  ou relevé importé) et la date d'import ; la
  catégorie s'y change avec le sélecteur visuel et la transaction s'y pointe. Le bouton
  corbeille supprime la transaction, après confirmation : elle n'est plus réimportée, ni par
  la synchronisation ni par l'import d'un relevé (marquée « Supprimée » dans l'aperçu).
  Le bouton **Transactions similaires** ferme la fenêtre et lance la recherche avec le
  libellé débarrassé du type d'opération (CB, PRLV SEPA, VIR…) et de ce qui suit le
  commerçant (numéro de carte, date, référence de mandat) : « PRLV SEPA EDF MDT/123 »
  cherche « EDF ». La recherche s'efface ensuite avec la croix, comme une recherche saisie.
- **Importer un relevé** : le bouton « Importer » ouvre une fenêtre où déposer le fichier
  téléchargé depuis l'espace client de la banque (OFX/QFX, QIF, CSV ou CAMT.053, 1 Mo au
  plus ; banques dont l'export CSV est vérifié : voir
  [Fonctionnalités](fonctionnalites.md#import-de-releves-bancaires)). Un aperçu classe chaque transaction : **nouvelle** (cochée), **doublon probable**
  (même montant qu'une transaction existante à 3 jours près, souvent la même synchronisée
  avec un autre libellé : non cochée) ou **déjà présente** (même identifiant : ignorée).
  Chaque transaction a sa case : décocher celles à ne pas importer (ou « Tout cocher » pour
  tout cocher ou décocher) ; le bouton indique le nombre de transactions importées.
  Les transactions importées appartiennent à l'utilisateur connecté et passent par les
  règles de catégorisation ; la liste s'ouvre ensuite sur le dernier mois importé
  (`POST /transaction/import/preview`, `POST /transaction/import`). Aucun compte synchronisé
  n'est nécessaire : sur un mois vide (nouvelle installation), le bouton est proposé au
  centre de la page, ce qui permet d'utiliser Carbure uniquement avec des relevés.

## Budget

![Écran Budgets : flux du mois en diagramme de Sankey](assets/screenshot-flow.png)

Deux sous-onglets : **Flux** (affiché par défaut) et **Budgets**.

- **Flux du mois** (sous-onglet Flux) : chiffres clés (revenus, dépenses, épargne et part
  des revenus épargnée, reste) puis diagramme de Sankey qui
  montre d'où vient l'argent du mois et où il va — revenus par catégorie → revenus du mois
  → dépenses par catégorie principale (les 8 plus importantes, le reste regroupé dans
  « Autres »), épargne et **Reste** (ou **Déficit**). Les virements internes (hors budget)
  sont exclus. « Voir le tableau » affiche les mêmes flux en tableau (`GET /budget/flow`).
  Un clic sur une catégorie (ou sur son flux) affiche ses transactions sous le diagramme
  (`GET /budget/flow/transactions`) ; un second clic les masque.
- **Synthèse du mois** (sous-onglet Budgets) : dépensé, budgété, reste (ou dépassement) et jauge globale des
  dépenses budgétées.
- **Cartes par catégorie** avec l'icône et la couleur de la catégorie, le montant
  dépensé sur le budget, le reste ou le dépassement ; le fond de la carte se remplit de
  gauche à droite à mesure que le budget est consommé (vert jusqu'à 105 % du budget, rouge
  au-delà). Le budget d'une catégorie parente précédé de « Σ » est la somme de ses
  sous-catégories.
- Sections séparées : **dépenses budgétées**, **revenus**, **hors budget et sans
  activité**.
- **Clic sur une catégorie** : liste de ses transactions du mois (sous-catégories
  comprises) avec leur total et le pointage ; si elle a des sous-catégories, leurs
  cartes s'affichent, avec un fil d'Ariane pour remonter.
- Bouton de réglage d'une carte : modification du montant budgété pour le mois. Pour une
  catégorie parente, la case **Somme des sous-catégories** utilise leur somme ; décochée,
  le montant saisi la remplace.
- **Recopier les budgets du mois précédent** : le bouton au-dessus de la synthèse reprend
  les budgets du mois précédent (`POST /budget/copy`), en complétant seulement les
  catégories sans budget ou en écrasant le mois affiché.

## Insights

Indicateurs du mois sélectionné, sous forme de cartes (icône, couleur, montant).

Un administrateur les ajoute (bouton **+**) et les modifie (crayon de chaque carte ; la
suppression se fait dans la modale) : un nom (20 caractères au plus), une couleur parmi
13, une icône et une requête SQL `SELECT … AS amount` où `{month}` et `{year}` sont
remplacés par le mois affiché. L'éditeur colore la syntaxe SQL et fait vérifier la requête
par le serveur pendant la saisie : erreur (mot-clé interdit, syntaxe MariaDB…) ou résultat
pour le mois affiché. La requête est exécutée en lecture seule et ne peut pas lire les
tables `users`, `api_tokens` et `devices` (voir [Sécurité](securite.md#insights-sql-stocke)).
Des exemples de requêtes sont dans [Recettes d'insights](insights.md).

## Tendances

![Écran Tendances du portail web Carbure](assets/screenshot-trends.png)

- Période de **3, 6 ou 12 mois** se terminant au mois courant.
- **Comparer à** : sans comparaison, la période précédente ou la même période l'an
  dernier.
- **Chiffres clés** : épargne cumulée, épargne moyenne par mois, taux d'épargne,
  dépenses moyennes par mois face au budget planifié ; en comparaison, l'écart (flèche,
  couleur et pourcentage) avec la période de référence.
- **Comparaison des totaux** (en mode comparaison) : crédit, débit, hors budget, budget
  planifié et épargne des deux périodes.
- **Revenus, dépenses et budget planifié** : barres mensuelles crédit / débit / hors
  budget et ligne du budget planifié, avec infobulle détaillée au survol.
- **Épargne mensuelle** : montants versés sur la catégorie **Epargne** et ses
  sous-catégories, moins les retraits (barres bleues, rouges pour un mois de retrait net).
  Le nom de la catégorie se règle avec `savings_category` (casse et accents ignorés). Le
  taux d'épargne est `épargne / revenus`.
- Vue **tableau** des données mois par mois.

## Règles (administrateurs)

Catégorisation automatique : une transaction dont le libellé contient le mot-clé reçoit
la catégorie choisie.

- **+** : ajout d'une règle (mot-clé + catégorie ou sous-catégorie) dans une modale. Le
  sélecteur de catégorie affiche l'icône, la couleur et le parent de chaque catégorie,
  regroupées par type (dépenses, revenus, hors budget), avec une recherche.
- Liste des règles regroupées par catégorie, filtrable, avec suppression.
- **Resynchroniser** : applique toutes les règles à toutes les transactions de la base,
  déjà catégorisées comprises, et affiche le nombre de transactions recatégorisées. Créer ou
  modifier une règle l'applique aussi immédiatement à tout l'historique (ce qui remplace une
  catégorie choisie à la main sur une transaction concernée) ; les nouvelles transactions
  sont catégorisées à chaque synchronisation.
- Un clic sur une règle ouvre sa fenêtre : modifier le mot-clé et la catégorie, voir combien
  de transactions contiennent le mot-clé (et combien sont déjà dans la catégorie), la
  supprimer (corbeille), et cocher **Envoyer une notification** : chaque nouvelle
  transaction synchronisée qui correspond à la règle envoie une notification à tous les
  utilisateurs du foyer (cloche sur la règle), en push ou par email selon les
  **Notifications** du profil de chacun.

## Catégories (administrateurs)

Arborescence des catégories (un niveau de sous-catégories) regroupée par type (dépenses,
revenus, hors budget). **+** ajoute une catégorie, le crayon la modifie (dans une modale),
la corbeille la supprime. L'icône et la couleur se choisissent parmi celles que
l'application iOS sait afficher, avec un aperçu. Supprimer une catégorie fait passer ses
transactions en « Non catégorisé » et supprime ses budgets et règles ; une catégorie qui a
des sous-catégories ne peut pas être supprimée. La catégorie « Non catégorisé » (0) est
protégée.

## Comptes (administrateurs)

La page est organisée par banque : chaque **banque connectée** (en-tête avec son nom et
ses actions) présente juste en dessous, en retrait, les **comptes suivis** qui en
viennent ; une banque sans compte suivi l'indique (« Aucun compte suivi : ajoutez des
comptes de cette banque »). Les comptes d'une banque qui n'est plus connectée dans woob,
ou importés depuis un fichier, sont regroupés à la fin sous **Autres comptes**.

- **Comptes du foyer** (partagés par tous les utilisateurs) : chaque compte suivi avec
  « Ajouté par » (l'utilisateur qui l'a ajouté), la date et le statut de sa dernière
  synchronisation (Synchronisé, Échec ou Jamais synchronisé ; le détail au survol), et
  trois actions : **synchroniser ce compte**, renommer, ne plus suivre (les transactions
  déjà importées sont conservées). Un compte s'affiche « BANQUE ···1234 » (banque et fin
  de l'identifiant), ou sous le **nom** qui lui a été donné (facultatif, ex. « Compte
  joint »), aussi dans la synchronisation et le détail d'une transaction.
- **Synchroniser tous les comptes** (icône de synchronisation en haut de la page) : lance
  la synchronisation de tous les comptes suivis, avec le même suivi en direct qu'un compte
  seul.
- **Ajout en deux étapes** :
    1. **Connecter une banque** (fenêtre modale) : choisir la banque parmi celles que
       woob supporte ; le formulaire demande les identifiants attendus par son module woob
       (numéro client, code, type de compte…), que **Connecter la banque** confie à woob
       (Carbure ne les enregistre ni ne les journalise). Une banque déjà connectée reste
       proposée (« autre identifiant ») pour un second accès avec d'autres identifiants
       (compte joint, professionnel…). Le champ facultatif **Nom de la connexion** lui donne
       un nom lisible (« Compte joint », « BNP pro »…), affiché à la place du nom de la
       banque ; son nom technique dans woob (`bnp`, `bnp_2`…) est généré automatiquement.
       Si la banque refuse les identifiants, son message s'affiche et le formulaire reste
       ouvert.
    2. **Banques connectées** : chaque connexion (backend woob) affiche son nom (à défaut
       celui de la banque) et son nom dans woob ; le bouton crayon la **renomme** (un nom
       vide rétablit celui de la banque) ; la corbeille la **supprime**, après confirmation
       dans une fenêtre du portail : woob oublie ses identifiants et ses comptes suivis ne le
       sont plus (leurs transactions sont conservées). La fenêtre reste ouverte, son bouton
       indiquant « Suppression en cours… », jusqu'à ce que woob ait supprimé la connexion ;
       une erreur s'y affiche, la suppression peut alors être relancée ou annulée. Son bouton **Ajouter des comptes** ouvre la liste des comptes que woob trouve pour elle
       (`woob bank list -b <banque>` : libellé, solde) ; on coche ceux à suivre et
       **Suivre les comptes sélectionnés** les ajoute d'un coup, nommés d'après leur libellé
       woob. Les comptes déjà suivis sont cochés et grisés. Après une connexion, cette liste
       s'ouvre directement.
- **Mettre à jour les modules bancaires** (administrateur) : met à jour les modules woob
  des banques (`woob config update`), par exemple après la correction d'un module cassé,
  avec le journal de woob en direct. La première fois, la fenêtre affiche le dépôt de woob
  et l'empreinte de sa clé, à comparer avec celle publiée par les mainteneurs de woob avant
  **Faire confiance et mettre à jour** ; les fois suivantes, la mise à jour démarre
  directement (voir [Sécurité](securite.md)). Tant que le dépôt n'est pas approuvé, une
  banque dont le module n'est pas encore installé ne peut pas être connectée : la fenêtre
  de connexion le signale.

  Seul un compte listé par woob peut être suivi : il n'y a plus de saisie manuelle de
  l'identifiant. La modification d'un compte ne change que son nom.
- **Synchronisation** : progression en direct, statut OK/KO de chaque étape par compte
  (opérations à venir, historique, notifications), nombre de transactions reçues et
  nouvelles, journal détaillé. Un échec des opérations à venir est affiché sur leur étape
  sans faire échouer le compte : l'historique est quand même synchronisé. Avec le réglage `woob_debug`, le journal affiche aussi, en
  direct, les logs de débogage de woob (requêtes vers la banque, trace de l'erreur Python),
  à un administrateur uniquement. La commande système lancée pour chaque étape (`Woob command …`)
  y figure toujours. En cas d'échec woob, un lien ouvre la recherche des tickets
  woob sur le module concerné. Après une synchronisation en échec, quelle que soit l'erreur, le
  bouton **Mettre à jour les modules bancaires** est aussi proposé sous le journal (un
  module woob obsolète en est la cause la plus fréquente). Si aucun compte n'a pu être synchronisé, la catégorisation
  est sautée : seule l'erreur est notifiée. Pendant une synchronisation, l'icône de l'onglet tourne.

La synchronisation de tous les comptes est planifiée côté serveur (`SYNC_INTERVAL` avec
Docker, ou une tâche cron).

### Authentification forte (2FA)

Certaines banques (Crédit Mutuel, CIC…) demandent régulièrement de valider la connexion
dans leur application. La synchronisation planifiée ne déclenche jamais cette validation :
elle réessaie d'abord le compte après `woob_2fa_wait` secondes (`woob_2fa_retries` fois :
la validation a pu être faite entre-temps sur le téléphone), puis, si la banque la demande
toujours, elle échoue, le compte passe en **Authentification forte requise** et chaque utilisateur
reçoit la notification « Strong authentication needed » sur l'app iOS. Le bouton
**Valider l'authentification forte** (sur la ligne du compte ou sous l'erreur) relance la
synchronisation de ce compte en mode interactif : woob déclenche la demande, son message
s'affiche (« validez dans votre application… ») et la synchronisation attend la validation
sur le téléphone. La session obtenue reste valable plusieurs semaines (souvent 90 jours,
règle DSP2) ; les synchronisations planifiées fonctionnent de nouveau jusque-là.

Les authentifications qui demandent de **saisir un code** (SMS) ne sont pas encore gérées.

## Agent IA

Connexion d'un agent IA (Claude, ChatGPT, Cursor, Copilot, Gemini…) au serveur MCP de
Carbure, en lecture seule. Voir [Agents IA (MCP)](mcp.md). L'onglet est dans le menu
**Administration** pour un administrateur, dans le menu utilisateur sinon.

- **Serveur MCP activé** : interrupteur réservé aux administrateurs (désactivé par
  défaut) ; les utilisateurs voient seulement son état.
- **Connecter Claude (web, Desktop, mobile)** : l'URL du serveur MCP à coller dans Claude
  (Paramètres → Connecteurs → Ajouter un connecteur personnalisé), sans jeton : Claude
  ramène au portail, qui demande d'**autoriser** l'accès.
- **+** : création d'un jeton d'accès (nom et durée de validité : 30 jours, 90 jours, 1 an
  ou sans expiration), possible seulement quand le serveur est activé. Le jeton et la
  configuration prête à copier pour chaque agent ne sont affichés qu'une fois.
- **Mes jetons d'accès** : date de création, dernière utilisation, expiration (ou
  « Expiré ») ; la corbeille révoque un jeton.

## Utilisateurs (menu Administration)

Liste des utilisateurs avec leur **profil** (Utilisateur ou Administrateur) et leur
**dernière connexion** ; un compte bloqué après trop d'échecs de connexion affiche
« Bloqué jusqu'au … » et un bouton **Débloquer**. **+** crée un utilisateur ; le crayon le
modifie (dont son profil) ; la corbeille le supprime. Le dernier administrateur ne peut
pas perdre son rôle, et un utilisateur propriétaire de transactions ne peut pas être
supprimé.

## Logs (menu Administration)

Les entrées des fichiers de log du serveur (`data/logs/carbure_AAAAMMJJ.log`), les plus
récentes d'abord : choix du fichier, niveau minimal (DEBUG, INFO, WARN, ERROR) et recherche
de texte. Chaque entrée indique l'adresse IP du client et l'utilisateur connecté ; un clic sur
l'identifiant d'une requête affiche toutes ses entrées, jusqu'à la ligne de fin (statut HTTP
et durée). Seule la fin d'un gros fichier (2 Mo) est lue ; les jetons de session et des
agents IA sont masqués. La durée de conservation des fichiers est rappelée en haut de page.

## Paramètres (menu Administration)

Le fichier de configuration (`data/conf/prod.ini`) section par section,
avec une description de chaque réglage. Les secrets (`db_password`, `jwtsecret`,
`password_salt`, `sync_token`…) ne sont jamais affichés. Seuls les réglages sans risque
sont modifiables — `log_level`, `log_retention_days`, `savings_category`, `public_url`, `woob_transactions`,
`woob_logging`, `woob_debug`, `woob_auto_update`, les identifiants APNs et le serveur
SMTP des emails — avec des
listes, des interrupteurs et des champs vérifiés par le serveur ; les autres portent un
cadenas. Les réglages modifiés sont surlignés ; la barre du bas les enregistre ou les
annule. Le fichier précédent est gardé en `prod.ini.bak` (`GET`/`PUT /system/config`).

Le mot de passe SMTP (`smtp_password`) n'est jamais affiché : on en saisit un nouveau pour
le remplacer. Dans la section **Emails (SMTP)**, **Envoyer un email de test** envoie un
email avec la configuration enregistrée, à votre adresse ou à celle saisie, et affiche le
résultat ou la réponse du serveur en cas d'échec (`POST /system/smtp-test`).

## Synchro (menu Administration)

La commande `curl` qui lance la synchronisation de tous les comptes (ou d'un seul) et la
ligne crontab correspondante, à copier pour une tâche planifiée (cron, tâche planifiée
Synology). Le `sync_token` y est masqué jusqu'à **Afficher** ; le bouton copier copie
toujours la commande complète. **Créer un jeton** / **Renouveler** en écrit un nouveau dans
la configuration (`GET /system/sync`, `POST /system/sync-token`).

## Mon profil

Trois sous-onglets, comme l'administration : **Profil**, **Notifications** et **Appareils**
(ouvert sur **Profil** depuis le menu utilisateur).

- **Profil** : modification de l'e-mail, du prénom, du nom et du mot de passe (laisser
  vide pour le conserver). L'identifiant n'est pas modifiable. **Langue** (français /
  anglais) : enregistrée sur le compte et appliquée à chaque connexion, sur tous les
  navigateurs. **Thème** (*Comme le système*, *Clair*, *Sombre*), comme le choix fait avec
  l'icône de l'en-tête (voir [Thèmes](#themes-et-preferences-du-systeme)).
- **Notifications** : un tableau type de notification × canal (push sur l'app iOS, email)
  pour la synchronisation, les règles « Notifier », les dépenses importantes et les budgets
  dépassés (désactivés par défaut). Chaque case s'enregistre aussitôt ; les notifications
  sont envoyées dans la **langue** du profil. Les réglages d'une alerte sont sous son
  libellé, dans le tableau, et s'enregistrent à la validation ou en quittant le champ (une
  valeur invalide est signalée sous le champ et n'est pas envoyée) :
  - **Dépense importante** : montant **à partir duquel** une nouvelle dépense, détectée
    lors d'une synchronisation, est notifiée (vide = désactivé) ;
  - **Budget dépassé** : le **budget surveillé**, *Tous les budgets* (par défaut) ou l'un
    des budgets du mois définis dans l'onglet **Budget**, dans son ordre (une sous-catégorie est présentée
    « Catégorie › Sous-catégorie »), et le pourcentage de ce budget auquel **alerter**
    (entier de 50 à 200, 100 par défaut).

  La colonne email est grisée, avec la raison, tant qu'aucun serveur SMTP n'est configuré
  ou que le profil n'a pas d'adresse email (`GET`/`PUT /user/notifications`).
- **Appareils** : appareils iOS enregistrés à la connexion depuis l'app, avec la
  date de dernière connexion et le token APNs, masqué par défaut. Les boutons permettent
  de l'afficher en entier, de le copier (HTTPS requis ; sinon le token est affiché) ou
  de **supprimer** un ancien appareil.

## Pied de page

Lien vers le site de Carbure ([carbure.org](https://carbure.org/)), licence et **version**
de l'instance (`v1.2.0`, ou `dev` hors release), lue dans `GET /api/health`.

## Accessibilité

Le portail vise le niveau AA des WCAG (score Lighthouse « Accessibilité » de 100 sur les
onglets et les fenêtres) :

- contrastes suffisants (rouge de la marque assombri à 5:1 sur blanc), au repos **et au
  survol** des boutons (un bouton à fond clair garde son fond au survol), et focus clavier
  toujours visible ;
- lien « Aller au contenu », repères de page (bannière, navigation, contenu principal) et
  onglet courant annoncé ;
- icônes décoratives masquées aux lecteurs d'écran, boutons à icône seule nommés, champs
  associés à leur libellé, fenêtres modales annoncées comme dialogues et fermées avec
  **Échap** ;
- messages (toasts, erreurs) annoncés par les lecteurs d'écran, langue de la page suivant
  celle de l'utilisateur ;
- graphiques doublés d'un tableau (Tendances, Flux du mois) et animations coupées quand le
  système demande moins de mouvements.

### Thèmes et préférences du système

Le portail suit les réglages d'affichage déjà choisis dans le système, sans thème à
activer :

- **clair ou sombre** : par défaut, le thème suit la préférence du système (et ses
  changements) ; le profil permet de le fixer (**Thème** : *Comme le système*, *Clair*,
  *Sombre*), choix enregistré sur le serveur et retrouvé sur un autre navigateur. Le thème
  est appliqué avant l'affichage de la page (`portal/theme.js`), sans éclair de l'autre
  thème ;
- **bouton de thème** : l'icône à droite de l'en-tête, juste avant le menu utilisateur,
  passe d'un clic au thème opposé à celui affiché (lune `dark_mode` en thème clair :
  *Passer au thème sombre* ; soleil `light_mode` en thème sombre : *Passer au thème
  clair*). Le choix est enregistré dans le profil comme le sélecteur **Thème**, qui le
  reflète ; pour suivre de nouveau le système, choisir *Comme le système* dans le
  profil. L'écran de connexion n'a pas ce bouton (il applique le thème mémorisé par le
  navigateur, sinon celui du système) ;
- **contraste augmenté** (macOS, iOS, `prefers-contrast: more`) : textes secondaires et
  bordures plus foncés (plus clairs en sombre), anneau de focus plus épais ;
- **couleurs forcées** (mode contraste élevé de Windows, `forced-colors`) : les couleurs
  sont celles du système ; boutons, onglets, champs, bascules, barres de budget, cartes et
  focus gardent une bordure ou un contour visible.

![Écran Transactions en thème sombre](assets/screenshot-theme-dark.png)

![Écran Transactions avec le contraste augmenté du système : textes secondaires et bordures plus foncés](assets/screenshot-theme-contrast.png)

![Écran Transactions en couleurs forcées (contraste élevé de Windows) : couleurs du système, chaque bouton, onglet et carte gardant une bordure](assets/screenshot-theme-forced-colors.png)

Le thème sombre respecte les mêmes règles que le thème clair (4,5:1 pour un texte, 3:1
pour une icône, au repos, au survol et au focus) : les couleurs d'accent y sont plus
claires et le texte posé dessus foncé ; les couleurs des catégories sont éclaircies
(`--category-ink`) pour rester lisibles sur fond sombre.

Les contrastes sont vérifiés par les tests e2e (`tests/e2e/specs/accessibility.spec.js`,
en thème clair et en thème sombre ; `themes.spec.js` pour le contraste augmenté et les
couleurs forcées) :
chaque texte de chaque onglet, des menus et des fenêtres, et chaque bouton au repos et au
survol doivent atteindre 4,5:1 (3:1 pour une icône ou un grand texte). Les couleurs
choisies par les utilisateurs (catégories) et les graphiques SVG ne sont pas mesurés.

## Mode debug

Ajouter `?debug=true` à l'URL affiche un panneau avec la dernière requête API et active
des logs dans la console du navigateur.

## Organisation du code

| Fichier | Rôle |
|---|---|
| `index.html` | Gabarit Vue (tous les écrans, dont l'assistant d'installation) |
| `app.js` | Application racine : authentification, onglets (onglets d'administration réservés), initialisation |
| `modules/*.js` | Mixins par domaine : `setup` (assistant), `transaction`, `import` (import de relevés), `budget`, `insights`, `trends`, `rules`, `categories`, `accounts`, `sync`, `agents`, `oauth` (consentement des agents IA), `logs`, `config` (Paramètres, email de test, Synchro), `user`, `profile` (profil, appareils, mise à jour de la base), `notifications` (canaux des notifications du profil), `confirm` (fenêtre de confirmation) |
| `services/apiService.js` | Appels HTTP vers l'API |
| `stores/budgetStore.js` | Navigation dans l'arborescence des budgets |
| `components/*.js` | Composants : diagramme de flux (`FlowChart`), sélecteur de catégorie (`CategoryPicker`, compact dans les listes), éditeur SQL (`SqlEditor`) |
| `i18n.js`, `i18n/*.json` | Chargement des traductions, et un fichier JSON par langue (`fr` de référence, `en`) |
| `utils/formatters.js`, `utils/categoryIcons.js` | Formatage des montants, dates, icônes ; icônes et couleurs des catégories |
| `vendor/` | Vue, CodeMirror (éditeur SQL) et polices, servis localement |
| `manifest.webmanifest`, `icons/` | Manifeste et icônes de l'application installée |
| `theme.js` | Thème clair ou sombre appliqué avant l'affichage de la page |
| `style.css` | Styles (variables CSS dans `:root`, thème sombre, contraste augmenté, couleurs forcées) |
