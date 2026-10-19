---
title: "Questions fréquentes"
description: "FAQ de Carbure : prix, banques prises en charge, où sont les données, prérequis, applications mobiles, notifications, foyer, import, sauvegarde, sécurité, agents IA et mises à jour."
icon: material/frequently-asked-questions
---

# Questions fréquentes

Une question qui n'est pas ici ? Posez-la sur le
[serveur Discord](https://discord.gg/QsxYw4Rx9D) (voir [Assistance](assistance.md)).

## Découvrir Carbure

??? question "Carbure est-il gratuit ?"
    Oui. Carbure est **gratuit et open source** (licence MIT) : le code est sur
    [GitHub](https://github.com/ltoinel/Carbure). Il n'y a ni abonnement, ni publicité, ni
    version payante. Vous l'hébergez vous-même : le seul coût est celui de la machine
    qui le fait tourner (un NAS, un mini-PC, un serveur).

??? question "Puis-je l'essayer sans rien installer ?"
    Oui, sur la **démo en ligne** [demo.carbure.org](https://demo.carbure.org/) avec le
    compte `demo` / `carbure` et des données fictives, ou chez vous en une commande :
    `docker run --rm -p 8080:80 ltoinel/carbure:demo` (voir
    [Installation](installation.md#essayer-carbure-demo)).

??? question "Quelle différence avec les autres applications de budget ?"
    Vos données restent **chez vous** : Carbure ne passe par aucun agrégateur bancaire
    commercial, et l'éditeur n'a accès ni à vos comptes ni à vos transactions. Le
    [Comparatif](comparatif.md) le situe face aux applications bancaires, aux agrégateurs
    et aux autres outils auto-hébergés.

## Banques et synchronisation

??? question "Quelles banques sont prises en charge ?"
    Les **banques françaises** prises en charge par [woob](https://woob.tech/), le
    projet open source que Carbure utilise pour récupérer les opérations. Une banque
    absente ou un module en panne ? Les relevés téléchargés depuis le site de la banque
    (OFX/QFX, QIF, CSV, CAMT.053) s'importent dans le portail (voir
    [Portail web](portail.md)).

??? question "Ma banque demande une validation sur mon téléphone, comment faire ?"
    Lancez la synchronisation depuis le portail : la demande de validation
    (authentification forte) s'y affiche, et la synchronisation attend que vous validiez
    dans l'application de votre banque ou saisissiez le code reçu. La synchronisation
    planifiée ne la déclenche jamais.

??? question "La synchronisation échoue, que faire ?"
    Consultez le journal de la synchronisation et la page **Logs** de l'administration,
    puis mettez à jour les modules bancaires de woob depuis le portail : une banque qui
    change son site casse souvent son module. Voir
    [En cas d'échec de la synchronisation](installation.md#en-cas-dechec-de-la-synchronisation).

## Installation

??? question "De quoi ai-je besoin pour l'installer ?"
    Le plus simple : une machine avec **Docker** (serveur, mini-PC, NAS) et une base
    **MariaDB ou MySQL**. L'image contient nginx, PHP et woob ; un assistant dans le
    navigateur crée le schéma et le compte administrateur. Sans Docker : PHP 8.2 ou plus,
    MariaDB ≥ 10.3 ou MySQL 8, un serveur web et woob. Voir [Installation](installation.md).

??? question "Fonctionne-t-il sur un NAS Synology ?"
    Oui, avec Container Manager et MariaDB 10 : le [tutoriel NAS Synology](synology.md)
    détaille chaque étape, jusqu'à l'accès HTTPS.

??? question "Comment mettre Carbure à jour ?"
    Récupérez la nouvelle image Docker et relancez le conteneur : les mises à jour de la
    base de données s'appliquent au démarrage, ou en un clic depuis le bandeau affiché aux
    administrateurs. Lisez les notes de la version avant une mise à jour majeure (voir
    [Installation](installation.md#mettre-a-jour)).

## Applications mobiles et notifications

??? question "Y a-t-il une application mobile ?"
    Oui, des applications natives **iPhone et Android** (transactions, pointage, budgets,
    aperçus, tendances, synchronisation, Face ID ou empreinte). En attendant leur
    publication sur l'App Store et Google Play, le portail s'installe comme une application
    sur le téléphone. Voir [Applications mobiles](applications-mobiles.md).

??? question "Comment connecter l'application à mon serveur ?"
    Dans le portail, **Mon profil → Mes appareils** affiche un QR code : scannez-le avec
    l'appareil photo du téléphone, l'application s'ouvre avec l'adresse de votre serveur
    déjà remplie. Vous pouvez aussi saisir l'adresse de l'API dans l'écran de connexion.
    Votre serveur doit être joignable depuis le téléphone (en HTTPS de préférence).

??? question "Pourquoi les notifications push passent-elles par Carbure Push ?"
    Apple et Google n'acceptent les notifications d'une application que depuis les
    identifiants de son éditeur : le relais **Carbure Push** (`api.carbure.org`) les
    détient, vous n'avez ni clé Apple ni compte Firebase à créer. Il transmet le titre et
    le texte des notifications sans les enregistrer, et ne connaît ni votre nom ni votre
    adresse e-mail (voir [Confidentialité](confidentialite.md)). Les notifications par
    e-mail (SMTP), elles, ne passent que par votre serveur.

## Données et sécurité

??? question "Où sont stockées mes données ?"
    Dans **votre** base de données, sur votre machine. Les identifiants bancaires sont
    gardés par woob sur votre serveur. Rien n'est envoyé à l'éditeur ; seules les
    notifications push, si l'administrateur les active, transitent par Carbure Push.

??? question "Comment protéger mon installation ?"
    Servez Carbure en **HTTPS**, activez la **double authentification** (application
    d'authentification, codes de secours), et suivez les recommandations de la page
    [Sécurité](securite.md). Les comptes sont bloqués après 5 échecs de connexion.

??? question "Comment sauvegarder mes données ?"
    Sauvegardez régulièrement la base de données (et le dossier `data/` du conteneur).
    Le menu **Administration → Sauvegarde** exporte aussi les données choisies
    (catégories, règles, budgets…) dans un fichier à réimporter. Voir
    [Portail web](portail.md#sauvegarde-menu-administration).

??? question "Plusieurs personnes du foyer peuvent-elles l'utiliser ?"
    Oui : chaque membre a son compte (identifiant, mot de passe, langue, thème,
    notifications, appareils), avec un profil **utilisateur** ou **administrateur**. Les
    comptes bancaires suivis sont partagés par le foyer.

## Agents IA

??? question "Puis-je interroger mon budget avec Claude ou ChatGPT ?"
    Oui, grâce au **serveur MCP** intégré, en lecture seule, qu'un administrateur active.
    Claude se connecte par OAuth, sans jeton à copier ; les autres agents (ChatGPT,
    Cursor, Copilot, Gemini…) avec un jeton d'accès. Voir [Agents IA (MCP)](mcp.md).

## Aide

??? question "Où obtenir de l'aide ou signaler un bug ?"
    Sur le [serveur Discord](https://discord.gg/QsxYw4Rx9D) ; un bug se
    signale par une [issue](https://github.com/ltoinel/Carbure/issues/new/choose) et une
    vulnérabilité en privé. Voir [Assistance](assistance.md).
