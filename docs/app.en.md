---
title: "Open Carbure on your server"
description: "Link that opens the Carbure mobile apps on a self-hosted server."
search:
  exclude: true
---

# Open Carbure on your server

This link opens the Carbure mobile app (iOS or Android) directly on your server: just
scan its QR code with the phone's camera. The app asks you to confirm the server, then
fills in the login form.

<p id="server-link" hidden>Server: <code id="server-link-url"></code></p>

If this page shows up on your phone, the app is not installed (or not up to date):
install it from the [App Store](https://apps.apple.com/fr/app/carbure/id6824057363) (iPhone) or
[Google Play](https://play.google.com/store/apps/details?id=org.carbure.app) (Android), then scan the QR code again. You can also enter the server address above in
the **Server URL** field of the login screen.

## Create your server's link { #creer-le-lien-de-votre-serveur }

The link has the following form, where the API address is encoded
(`encodeURIComponent`); the optional `user` parameter prefills the username:

```text
https://carbure.org/app/?server=https%3A%2F%2Fcarbure.example.com%2Fapi%2F&user=marie
```

Only an `http` or `https` address is accepted. Only scan QR codes from your own server:
your password is sent to it when you log in.

<script>
  (function () {
    var server = new URLSearchParams(window.location.search).get("server");
    if (!server || !/^https?:\/\/[^\s/]+/i.test(server)) return;
    document.getElementById("server-link-url").textContent = server;
    document.getElementById("server-link").hidden = false;
  })();
</script>
