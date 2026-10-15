# Third-party assets (self-hosted)

Swagger UI makes no request to external services: these files are served with it.

| File | Project | Version | License |
|---|---|---|---|
| `swagger-ui/swagger-ui.css`, `swagger-ui-bundle.js`, `swagger-ui-standalone-preset.js` | [Swagger UI](https://github.com/swagger-api/swagger-ui) (`swagger-ui-dist`) | 5.33.0 | Apache License 2.0 (`swagger-ui/LICENSE`, `swagger-ui/NOTICE`) |

SHA-256 of the files, as published in the npm package (integrity of the tarball checked
against the registry: `sha512-wpdK+m6BU5yj6pmUdMskZVTSWYG4DLglAx3sIhylloY37i8O37IrH+YEpqdXNfpaTGxILRBFzUqLF2jKqbfI7A==`):

```
62df541529080464a7660adc793eab7128c6193ce3be24ddc1e0e0a4a63edc2f  swagger-ui-bundle.js
5243d492e14505e0cab87ac8b0195d0e615943e651743b2b698450a46eb470be  swagger-ui-standalone-preset.js
1ac324f7dcd27e4b9386b4bd6421271ec147e922a22c05ba24b11515e9aa6321  swagger-ui.css
```

To upgrade, download `https://registry.npmjs.org/swagger-ui-dist/-/swagger-ui-dist-<version>.tgz`,
check it against the `dist.integrity` of `https://registry.npmjs.org/swagger-ui-dist`, copy the
three files above with `LICENSE` and `NOTICE` here, and update this table.
