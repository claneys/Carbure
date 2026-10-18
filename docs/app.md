---
title: "Ouvrir Carbure sur votre serveur"
description: "Lien d'ouverture des applications mobiles Carbure sur un serveur auto-hébergé."
search:
  exclude: true
---

# Ouvrir Carbure sur votre serveur

Ce lien ouvre l'application mobile Carbure (iOS ou Android) directement sur votre serveur :
il suffit de scanner son QR code avec l'appareil photo du téléphone. L'application demande
de confirmer le serveur, puis remplit le formulaire de connexion.

<p id="server-link" hidden>Serveur : <code id="server-link-url"></code></p>

Si cette page s'affiche sur votre téléphone, l'application n'est pas installée (ou pas à
jour) : installez-la, puis scannez à nouveau le QR code. Vous pouvez aussi saisir l'adresse
du serveur ci-dessus dans le champ **URL du serveur** de l'écran de connexion.

## Créer le lien de votre serveur

Le lien a la forme suivante, où l'adresse de l'API est encodée (`encodeURIComponent`) ; le
paramètre `user`, facultatif, préremplit le nom d'utilisateur :

```text
https://carbure.org/app/?server=https%3A%2F%2Fcarbure.example.com%2Fapi%2F&user=marie
```

Seule une adresse `http` ou `https` est acceptée. Ne scannez que les QR codes de votre propre
serveur : votre mot de passe lui est envoyé à la connexion.

<script>
  (function () {
    var server = new URLSearchParams(window.location.search).get("server");
    if (!server || !/^https?:\/\/[^\s/]+/i.test(server)) return;
    document.getElementById("server-link-url").textContent = server;
    document.getElementById("server-link").hidden = false;
  })();
</script>
