---
title: "Politique de confidentialité des applications Carbure"
description: "Données traitées par les applications mobiles Carbure (iOS, Android), par le serveur Carbure auto-hébergé et par le service de notifications Carbure Push : ce qui est collecté, où, pourquoi, combien de temps, et vos droits."
icon: material/shield-account-outline
---

# Politique de confidentialité

*Dernière mise à jour : 19 octobre 2026*

Cette politique s'applique aux applications mobiles **Carbure** pour iPhone et Android
(« l'application »), éditées par Ludovic Toinel, et au service de notifications
**Carbure Push** (`api.carbure.org`) qu'il exploite.

## En résumé

- L'application se connecte **uniquement au serveur Carbure que vous indiquez** :
  le vôtre ou celui de votre foyer, installé et administré par vous. L'éditeur n'a
  **aucun accès** à vos comptes, à vos transactions ni à vos identifiants bancaires.
- **Aucune publicité, aucun outil de mesure d'audience, aucun suivi**, aucun rapport de
  plantage envoyé à un tiers, aucune revente de données.
- Vos données financières ne sont **pas enregistrées sur le téléphone** : elles sont
  chargées depuis votre serveur à chaque ouverture et restent en mémoire.
- Seules les **notifications push** passent par un service de l'éditeur (Carbure Push),
  puis par Apple ou Google : leur texte n'y est **ni conservé ni journalisé**.

## Qui traite vos données

| Traitement | Responsable |
|---|---|
| Vos comptes, transactions, budgets, catégories, votre compte utilisateur | L'**administrateur du serveur Carbure** auquel vous vous connectez (vous-même ou une personne de votre foyer) : le logiciel est auto-hébergé, les données restent sur sa machine |
| Relais des notifications push (Carbure Push) | Ludovic Toinel, éditeur de l'application |
| Acheminement des notifications jusqu'au téléphone | Apple (APNs) sur iPhone, Google (Firebase Cloud Messaging) sur Android, selon leurs propres politiques de confidentialité |

## Données enregistrées sur le téléphone

| Donnée | Pourquoi | Où |
|---|---|---|
| Adresse de votre serveur Carbure | Savoir à quel serveur se connecter | Préférences de l'application |
| Dernier nom d'utilisateur saisi | Pré-remplir l'écran de connexion | Préférences de l'application |
| Choix de la connexion biométrique, du thème | Vos réglages | Préférences de l'application |
| Jeton de notification push (fourni par Apple ou Google) | Recevoir les notifications | Préférences de l'application |
| Nom d'utilisateur et mot de passe | **Uniquement si vous activez la connexion par Face ID / empreinte** : se reconnecter sans les saisir | Trousseau iOS (*Keychain*, limité à cet appareil) ; sur Android, chiffrés (AES-256) avec une clé du *Keystore* |

- La **session** (jeton de connexion) et les **données financières** ne sont gardées qu'en
  mémoire : elles disparaissent à la fermeture de l'application.
- Les **données biométriques** (visage, empreinte) sont vérifiées par le système du
  téléphone : l'application n'y a jamais accès et ne les transmet pas.
- Sur Android, la sauvegarde automatique des données de l'application est désactivée.
- Le **mode démo** fonctionne avec des données fictives générées sur le téléphone, sans
  aucune connexion.

## Données envoyées à votre serveur Carbure

À la connexion, l'application envoie à **votre** serveur : votre nom d'utilisateur et votre
mot de passe, le **modèle** de l'appareil (par exemple « iPhone16,1 » ou « Google Pixel 8 »,
pas le nom que vous lui avez donné), son système (iOS ou Android) et son jeton de
notification push. Le serveur s'en sert pour vous authentifier, reconnaître l'appareil
(il peut alors se dispenser de la double authentification) et vous envoyer des
notifications. Ensuite, l'application ne fait que lire et modifier vos données Carbure
(transactions, budgets, catégories, profil) sur ce serveur.

Ce serveur est exploité par son administrateur : la durée de conservation, les sauvegardes
et la sécurité de ces données relèvent de lui (voir la documentation
[Sécurité](securite.md)). Utilisez une adresse en **HTTPS** pour que ces échanges soient
chiffrés.

## Notifications push et Carbure Push

Si l'administrateur de votre serveur a activé les notifications (après avoir accepté les
conditions du service Carbure Push), votre serveur envoie chaque notification au relais
**Carbure Push** (`api.carbure.org`), qui la transmet à Apple ou à Google, qui la délivrent
à votre téléphone.

- **Contenu transmis** : le jeton push de l'appareil, le titre et le texte de la
  notification (par exemple le libellé et le montant d'une dépense, le nom d'un budget
  dépassé, le nom de la banque d'une synchronisation en échec) et le nombre affiché sur
  l'icône. Le relais n'apprend ni votre nom, ni votre adresse e-mail, ni votre adresse IP :
  seul votre serveur l'appelle.
- **Non conservé** : le titre et le texte des notifications ne sont **ni enregistrés ni
  journalisés** par le relais ; ils transitent en mémoire, de façon chiffrée (HTTPS)
  jusqu'à Apple ou Google.
- **Conservé par le relais** :
    - une **empreinte** (SHA-256) du jeton push, jamais le jeton lui-même, avec la date de
      dernière utilisation : supprimée quand Apple ou Google signale le jeton comme
      invalide (application désinstallée), quand le serveur libère l'appareil ou quand
      sa clé est révoquée ;
    - pour chaque **serveur Carbure** inscrit : sa clé d'accès (empreinte), le nom d'hôte
      qu'il déclare, l'adresse IP du serveur lors de l'inscription et un compteur
      quotidien du nombre de notifications ;
    - des journaux techniques limités à l'identifiant de la clé, aux compteurs et aux
      8 premiers caractères d'un jeton en erreur.
- **Désactiver** : dans le portail Carbure, **Mon profil → Notifications** permet de
  choisir, type par type, de recevoir ou non chaque notification ; vous pouvez aussi
  refuser ou retirer l'autorisation des notifications dans les réglages du téléphone.

Apple et Google traitent les notifications selon leurs propres politiques. Sur Android,
la bibliothèque **Firebase Cloud Messaging** de Google, intégrée à l'application pour
recevoir les notifications, échange avec Google un identifiant d'installation et des
informations techniques sur l'appareil. C'est le **seul composant tiers** de l'application
qui communique des données ; aucun service d'analyse de Firebase n'est utilisé.

## Autorisations demandées

| Autorisation | Usage |
|---|---|
| Notifications | Afficher les alertes de votre serveur (synchronisation, dépenses, budgets) ; facultative |
| Face ID / empreinte digitale | Déverrouiller la connexion si vous activez cette option ; facultative |
| Accès à Internet (Android) | Joindre votre serveur Carbure |

L'application n'accède ni à votre position, ni à vos contacts, ni à vos photos, ni à
l'appareil photo, et n'utilise pas l'identifiant publicitaire.

## Conservation et suppression

- **Sur le téléphone** : la désinstallation de l'application efface toutes les données
  qu'elle a enregistrées. La déconnexion efface la session mais garde l'adresse du serveur
  et le nom d'utilisateur ; les identifiants enregistrés pour la connexion biométrique
  sont effacés quand vous désactivez cette option.
- **Sur votre serveur Carbure** : votre compte et ses appareils sont supprimés par
  l'administrateur du serveur (le compte d'un utilisateur est supprimé avec ses
  appareils). Adressez-vous à lui pour consulter, corriger, exporter ou supprimer vos
  données.
- **Sur Carbure Push** : voir ci-dessus ; vous pouvez demander la suppression des données
  liées à votre serveur à l'adresse de contact ci-dessous.

## Vos droits

Conformément au Règlement général sur la protection des données (RGPD), vous disposez d'un
droit d'accès, de rectification, d'effacement, de limitation, d'opposition et de
portabilité. Pour les données de votre serveur Carbure, exercez-les auprès de son
administrateur ; pour Carbure Push, auprès de l'éditeur (contact ci-dessous). Vous pouvez
aussi adresser une réclamation à la [CNIL](https://www.cnil.fr/).

L'application est destinée à la gestion du budget d'un foyer ; elle ne s'adresse pas
spécifiquement aux enfants et ne collecte sciemment aucune donnée les concernant.

## Contact

Ludovic Toinel — [contact@carbure.org](mailto:contact@carbure.org)

Pour une question sur l'application qui ne concerne pas vos données personnelles,
vous pouvez aussi utiliser les [Discussions GitHub](https://github.com/ltoinel/Carbure/discussions).

Cette politique peut évoluer avec l'application ; la date de dernière mise à jour figure
en haut de la page.
