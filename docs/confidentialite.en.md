---
title: "Privacy policy of the Carbure apps"
description: "Data processed by the Carbure mobile apps (iOS, Android), by the self-hosted Carbure server and by the Carbure Push notification service: what is collected, where, why, for how long, and your rights."
icon: material/shield-account-outline
---

# Privacy policy

*Last updated: October 19, 2026*

This policy applies to the **Carbure** mobile apps for iPhone and Android
("the app"), published by Ludovic Toinel, and to the **Carbure Push** notification service
(`api.carbure.org`) that he operates.

## In short { #en-resume }

- The app connects **only to the Carbure server you enter**:
  yours or your household's, installed and administered by you. The publisher has
  **no access** to your accounts, your transactions or your bank credentials.
- **No advertising, no audience measurement tools, no tracking**, no crash reports
  sent to a third party, no resale of data.
- Your financial data is **not stored on the phone**: it is
  loaded from your server each time the app is opened and stays in memory.
- Only **push notifications** go through a service of the publisher (Carbure Push),
  then through Apple or Google: their text is **neither kept nor logged** there.

## Who processes your data { #qui-traite-vos-donnees }

| Processing | Controller |
|---|---|
| Your accounts, transactions, budgets, categories, your user account | The **administrator of the Carbure server** you connect to (yourself or someone in your household): the software is self-hosted, the data stays on their machine |
| Push notification relay (Carbure Push) | Ludovic Toinel, publisher of the app |
| Delivery of notifications to the phone | Apple (APNs) on iPhone, Google (Firebase Cloud Messaging) on Android, under their own privacy policies |

## Data stored on the phone { #donnees-enregistrees-sur-le-telephone }

| Data | Why | Where |
|---|---|---|
| Address of your Carbure server | Know which server to connect to | App preferences |
| Last username entered | Pre-fill the login screen | App preferences |
| Choice of biometric login, of the theme | Your settings | App preferences |
| Push notification token (provided by Apple or Google) | Receive notifications | App preferences |
| Username and password | **Only if you enable Face ID / fingerprint login**: log back in without typing them | iOS *Keychain* (restricted to this device); on Android, encrypted (AES-256) with a *Keystore* key |

- The **session** (login token) and the **financial data** are only kept in
  memory: they disappear when the app is closed.
- **Biometric data** (face, fingerprint) is checked by the phone's
  system: the app never has access to it and does not transmit it.
- On Android, automatic backup of the app's data is disabled.
- **Demo mode** works with fictitious data generated on the phone, without
  any connection.

## Data sent to your Carbure server { #donnees-envoyees-a-votre-serveur-carbure }

At login, the app sends to **your** server: your username and your
password, the device **model** (for example "iPhone16,1" or "Google Pixel 8",
not the name you gave it), its operating system (iOS or Android) and its push notification
token. The server uses them to authenticate you, recognize the device
(it can then skip two-factor authentication) and send you
notifications. After that, the app only reads and modifies your Carbure data
(transactions, budgets, categories, profile) on that server.

This server is operated by its administrator: the retention period, backups
and security of this data are their responsibility (see the
[Security](securite.md) documentation). Use an **HTTPS** address so that these exchanges are
encrypted.

## Push notifications and Carbure Push { #notifications-push-et-carbure-push }

If your server's administrator has enabled notifications (after accepting the
terms of the Carbure Push service), your server sends each notification to the
**Carbure Push** relay (`api.carbure.org`), which forwards it to Apple or Google, which deliver it
to your phone.

- **Content transmitted**: the device's push token, the title and text of the
  notification (for example the label and amount of an expense, the name of an exceeded
  budget, the name of the bank of a failed sync) and the number shown on
  the icon. The relay learns neither your name, nor your email address, nor your IP address:
  only your server calls it.
- **Not kept**: the title and text of notifications are **neither stored nor
  logged** by the relay; they pass through memory, encrypted (HTTPS)
  all the way to Apple or Google.
- **Kept by the relay**:
    - a **hash** (SHA-256) of the push token, never the token itself, with the date it was
      last used: deleted when Apple or Google reports the token as
      invalid (app uninstalled), when the server releases the device or when
      its key is revoked;
    - for each registered **Carbure server**: its access key (hash), the hostname
      it declares, the server's IP address at registration and a
      daily counter of the number of notifications;
    - technical logs limited to the key identifier, the counters and the
      first 8 characters of a token in error.
- **Turning off**: in the Carbure portal, **My profile → Notifications** lets you
  choose, type by type, whether or not to receive each notification; you can also
  deny or withdraw the notification permission in the phone's settings.

Apple and Google process notifications under their own policies. On Android,
Google's **Firebase Cloud Messaging** library, built into the app to
receive notifications, exchanges an installation identifier and technical
information about the device with Google. It is the **only third-party component** of the app
that communicates data; no Firebase analytics service is used.

## Permissions requested { #autorisations-demandees }

| Permission | Use |
|---|---|
| Notifications | Show alerts from your server (sync, expenses, budgets); optional |
| Face ID / fingerprint | Unlock login if you enable this option; optional |
| Internet access (Android) | Reach your Carbure server |

The app does not access your location, your contacts, your photos or
the camera, and does not use the advertising identifier.

## Retention and deletion { #conservation-et-suppression }

- **On the phone**: uninstalling the app erases all the data
  it has stored. Logging out erases the session but keeps the server address
  and the username; the credentials stored for biometric login
  are erased when you turn that option off.
- **On your Carbure server**: your account and its devices are deleted by
  the server's administrator (a user's account is deleted along with its
  devices). Contact them to view, correct, export or delete your
  data.
- **On Carbure Push**: see above; you can request the deletion of the data
  linked to your server at the contact address below.

## Your rights { #vos-droits }

Under the General Data Protection Regulation (GDPR), you have a
right of access, rectification, erasure, restriction, objection and
portability. For the data on your Carbure server, exercise them with its
administrator; for Carbure Push, with the publisher (contact below). You can
also lodge a complaint with the [CNIL](https://www.cnil.fr/).

The app is intended for managing a household's budget; it is not
specifically aimed at children and does not knowingly collect any data about them.

## Contact { #contact }

Ludovic Toinel — [contact@carbure.org](mailto:contact@carbure.org)

For a question about the app that does not concern your personal data,
you can also use [GitHub Discussions](https://github.com/ltoinel/Carbure/discussions).

This policy may change along with the app; the date of the last update appears
at the top of the page.
