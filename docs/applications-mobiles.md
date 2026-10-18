---
title: "Applications mobiles iPhone et Android"
description: "Les applications Carbure pour iPhone et Android : connexion à votre serveur, Face ID et empreinte, transactions, budgets, aperçus, tendances, synchronisation et notifications push."
icon: material/cellphone
---

# Applications mobiles

Carbure existe en **application native** pour **iPhone** (SwiftUI) et pour **Android**
(Jetpack Compose). Les deux offrent les mêmes écrans et se connectent à **votre** serveur
Carbure, par la même [API](api.md) que le portail web : aucune donnée ne passe par un
service tiers, sauf les notifications push (voir [plus bas](#notifications-push)).

![Transactions du mois dans l'app iPhone](assets/mobile/ios-transactions.png){ loading=lazy }
![Budgets du mois dans l'app Android](assets/mobile/android-budgets.png){ loading=lazy }
![Tendances sur 24 mois dans l'app iPhone](assets/mobile/ios-tendances.png){ loading=lazy }
{ .mobile-screens }

!!! info "Disponibilité"
    Les applications seront prochainement disponibles sur l'**App Store** et sur
    **Google Play**. En attendant, le portail web
    [s'installe comme une application](portail.md#installer-le-portail-comme-une-application)
    sur le téléphone.

Les apps sont faites pour **le suivi au quotidien** : consulter, pointer, catégoriser,
ajuster un budget, lancer une synchronisation. L'administration (comptes bancaires, règles,
catégories, insights, utilisateurs, paramètres) et l'import de relevés restent dans le
[portail web](portail.md).

| | iPhone | Android |
|---|---|---|
| Système minimum | iOS 26 | Android 8.0 |
| Langues | français, anglais (celle du téléphone) | français, anglais (celle du téléphone) |
| Connexion sans mot de passe | Face ID / Touch ID | empreinte ou visage (biométrie forte) |
| Notifications push | Apple (APNs) | Google (Firebase Cloud Messaging) |
| Thème | système, clair ou sombre | système, clair ou sombre |

## Connexion

L'écran de connexion demande l'**identifiant** et le **mot de passe** du compte Carbure.

![Écran de connexion de l'app iPhone, avec le bouton Essayer la démo](assets/mobile/ios-connexion.png){ loading=lazy }
![Écran de connexion de l'app Android](assets/mobile/android-connexion.png){ loading=lazy }
{ .mobile-screens }

- **Adresse du serveur** : le bouton ⚙ en haut à droite affiche le champ de l'adresse de
  l'API (`https://<serveur>/api/`) ; elle doit être en `http` ou `https`, sans identifiant
  ni mot de passe dans l'URL. Elle est enregistrée à la connexion, avec le dernier
  identifiant saisi.
- **Essayer la démo** : connexion au [serveur de démonstration](https://demo.carbure.org/)
  avec un compte partagé, sans toucher au serveur ni au mot de passe enregistrés. Un
  badge **DÉMO** s'affiche sous le mois.
- **Version du serveur** : avant chaque connexion, l'app lit `GET /health` ; si le serveur
  exige une version plus récente de l'app (`min_mobile_version`), la connexion est refusée
  avec un message invitant à mettre l'app à jour.
- **Messages d'erreur** : identifiants incorrects, compte bloqué (après 5 échecs, voir
  [Sécurité](securite.md)), serveur introuvable ou injoignable.
- **Appareil enregistré** : à la connexion, l'app envoie le nom et la plateforme du
  téléphone et son jeton de notification ; l'appareil apparaît dans **Mon profil → Mes
  appareils** du portail, d'où il peut être supprimé.
- **Session** : le jeton de session reste en mémoire ; il faut se reconnecter (par mot de
  passe ou biométrie) à chaque lancement de l'app.

### Double authentification

Quand la [double authentification](securite.md#double-authentification-totp) est imposée,
un second écran demande le **code à 6 chiffres** de l'application d'authentification, ou
un **code de secours** (`xxxxx-xxxxx`). Si l'utilisateur n'a pas encore d'application
d'authentification, l'app affiche la clé à ajouter, avec les boutons **Ajouter à une
application d'authentification** (lien `otpauth://`) et **Copier la clé**, puis, une fois
le premier code accepté, les **10 codes de secours** à copier et conserver avant d'ouvrir
la session. Le code n'est plus demandé ensuite depuis ce téléphone.

### Face ID et empreinte

Dans **Réglages → Sécurité**, la **connexion biométrique** (Face ID ou Touch ID sur
iPhone, empreinte ou visage sur Android) enregistre l'identifiant et le mot de passe à la
connexion suivante :

- sur iPhone, dans le **trousseau** (*Keychain*), limité à cet appareil ;
- sur Android, chiffrés par une clé du **Keystore** qui exige la biométrie à chaque
  usage ; un nouveau visage ou une nouvelle empreinte enregistré sur le téléphone les
  efface.

L'écran de connexion propose alors la biométrie automatiquement. Le mot de passe n'est
envoyé qu'au serveur pour lequel il a été saisi ; désactiver l'option, changer de serveur
ou un mot de passe refusé l'efface. Se déconnecter le conserve.

### Lien de connexion

Un lien `https://carbure.org/app/?server=<adresse de l'API>&user=<identifiant>`, ouvert
sur le téléphone (ou scanné en QR code avec l'appareil photo), ouvre l'app et pré-remplit
le serveur et l'identifiant, après confirmation : l'app prévient que le mot de passe sera
envoyé à ce serveur et que la session en cours sera fermée.

## Chargement

Après la connexion, l'app charge le mois en cours (catégories, budgets, transactions,
aperçus) en affichant chaque étape ; en cas d'échec, **Réessayer** relance le chargement.

## Écran principal

- **En-tête** : le menu (☰), le **mois** affiché avec ses flèches précédent / suivant, et
  un interrupteur qui **masque les transactions pointées** (dans toutes les listes et le
  compteur) pour ne garder que celles à vérifier.
- **Barre d'onglets** : **Transactions** (avec leur nombre), **Budget** et **Aperçus**.
  Changer de mois recharge les trois.

### Transactions

La liste des transactions du mois : icône et couleur de la catégorie, coche si la
transaction est pointée, libellé, date et montant (vert pour un crédit, rouge pour une
dépense de plus de 100 €).

![Transactions du mois dans l'app Android](assets/mobile/android-transactions.png){ loading=lazy }
{ .mobile-screens }

Une transaction ouvre son **détail** (catégorie, montant, libellé, date, carte) avec deux
actions :

- **Catégoriser** : choix de la catégorie dans l'arborescence (une catégorie parente
  ouvre ses sous-catégories) ; la transaction est aussi pointée.
- **Pointer** : marque la transaction comme vérifiée.

Comme dans le portail, ces actions sont communes au foyer (voir
[Fonctionnalités](fonctionnalites.md#transactions)).

### Budget

Les catégories du mois, celles qui ont un budget d'abord : montant dépensé et, s'il y a
un budget, une barre de progression avec le pourcentage (orange à partir de 80 %, rouge
au-delà de 100 %).

Une catégorie ouvre son **détail** : un **anneau** du budget consommé, puis ses
**sous-budgets** (si elle a des sous-catégories) ou ses **transactions** du mois. Le
bouton ⚙ **modifie le montant du budget** de ce mois (montant entier en euros).

![Budgets du mois dans l'app iPhone](assets/mobile/ios-budgets.png){ loading=lazy }
{ .mobile-screens }

### Aperçus

Les [insights](insights.md) du mois définis sur le serveur (dépenses, revenus, reste,
épargne…), en tuiles, et un **graphique en carrés** du mois : chaque carré vaut 100 €
(l'échelle s'adapte), en rouge ce qui est dépensé, en bleu le budget prévu restant et en
vert le reste des revenus.

![Aperçus du mois dans l'app iPhone](assets/mobile/ios-apercus.png){ loading=lazy }
![Aperçus du mois dans l'app Android](assets/mobile/android-apercus.png){ loading=lazy }
{ .mobile-screens }

## Menu

### Tendances

Les **24 derniers mois** : revenus au-dessus de l'axe, dépenses en dessous et la courbe du
solde (bleu quand le mois a permis de mettre de côté, orange en cas de dépassement).

- Toucher ou glisser sur le graphique affiche le solde d'un mois.
- La légende masque ou affiche chaque série.
- Sous le graphique : l'évolution des dépenses (moitié récente de la période comparée à la
  plus ancienne), les dépenses et revenus moyens, le solde cumulé, le nombre de mois en
  dépassement, et les mois aux dépenses les plus fortes et les plus faibles.

![Tendances sur 24 mois dans l'app Android](assets/mobile/android-tendances.png){ loading=lazy }
{ .mobile-screens }

### Synchronisation

Lance la [synchronisation bancaire](fonctionnalites.md#deroulement-dune-synchronisation)
de tous les comptes et affiche son **journal en direct**, coloré selon le niveau (info,
succès, avertissement, erreur). Quitter l'écran l'interrompt.

!!! warning "Validation par la banque"
    Quand la banque demande une validation (code, SMS), l'app l'affiche dans le journal
    mais ne permet pas d'y répondre : validez-la depuis le
    [portail](portail.md#authentification-forte-2fa).

### Réglages

- **Serveur** : l'adresse du serveur ; en changer déconnecte et oublie le mot de passe
  enregistré. **Rétablir** remet l'adresse par défaut.
- **Apparence** : **Système**, **Clair** ou **Sombre**, appliqué tout de suite et
  enregistré dans le profil : le même thème est retrouvé dans le portail et sur les autres
  appareils.
- **Sécurité** : la [connexion biométrique](#face-id-et-empreinte).
- **Notifications** : **Tester la notification push** envoie une notification de test à
  chaque appareil du compte et indique le résultat.

![Budgets en thème sombre dans l'app iPhone](assets/mobile/ios-mode-sombre.png){ loading=lazy }
![Budgets en thème sombre dans l'app Android](assets/mobile/android-mode-sombre.png){ loading=lazy }
{ .mobile-screens }

**À propos** donne la version de l'app ; **Déconnexion** ferme la session après
confirmation.

## Notifications push

Au premier lancement, l'app demande l'autorisation d'afficher des notifications (sur
Android à partir de la version 13). Elle reçoit ensuite les notifications du serveur,
y compris quand elle est ouverte :

- résultat de la synchronisation de chaque compte ;
- dépense importante, au-dessus du seuil de l'utilisateur ;
- transaction d'une règle « Envoyer une notification » ;
- budget du mois atteint.

Le contenu, les destinataires, la langue et le choix des canaux (push, email) sont décrits
dans [Fonctionnalités](fonctionnalites.md#notifications) ; ils se règlent dans **Mon
profil → Notifications** du portail. Sur iPhone, le **badge** de l'app indique le nombre
de transactions à vérifier.

Les notifications passent par le relais **Carbure Push**, que l'administrateur active
pour l'instance (voir [Configuration](configuration.md#push)), puis par Apple (APNs) ou
Google (Firebase Cloud Messaging). Sans cette inscription, les apps fonctionnent mais ne
reçoivent pas de push.

## Confidentialité

Les apps ne se connectent qu'au serveur que vous indiquez (et au serveur de démo si vous
l'essayez), sans publicité ni mesure d'audience. Les données bancaires restent en mémoire
et disparaissent à la fermeture de l'app ; seuls l'adresse du serveur, le dernier
identifiant, le thème et, si vous l'activez, le mot de passe protégé par la biométrie sont
gardés sur le téléphone. Détails : [Politique de confidentialité](confidentialite.md).

## Limites

- Pas de **mode hors ligne** : il faut joindre le serveur.
- Pas de **recherche** ni de modification des transactions au-delà du pointage et de la
  catégorie ; pas de retour d'une transaction pointée à « non vérifiée ».
- Pas de **suppression** de budget, ni d'administration (comptes, règles, catégories,
  insights, utilisateurs) : ces écrans sont dans le portail.
- Montants en **euros**, comme le serveur.
