# Security policy

Carbure handles sensitive data: bank transactions, and the bank credentials kept by woob.
Thank you for helping to keep it safe.

## Supported versions

Only the **latest release** (and the `ltoinel/carbure:latest` Docker image) receives
security fixes. Please update before reporting an issue.

| Version | Supported |
|---|---|
| Latest release | ✅ |
| Older releases | ❌ |

## Reporting a vulnerability

**Do not open a public issue.** Report it privately through GitHub:
[**Report a vulnerability**](https://github.com/ltoinel/Carbure/security/advisories/new)
(Security tab → *Report a vulnerability*).

Please include:

- the affected version (or commit) and how Carbure is deployed (Docker image, other);
- the steps to reproduce, and a proof of concept if you have one;
- the impact you expect (data read or changed, authentication bypass…).

Never include real bank data, credentials or tokens; use the sample data of `./start.sh`.

## What to expect

Carbure is maintained on spare time, on a best-effort basis:

- acknowledgement within **7 days**;
- a first assessment (accepted or not, severity) within **30 days**;
- for an accepted vulnerability: a fix in a new release, then a GitHub security advisory
  that credits you, unless you prefer to stay anonymous.

Please keep the vulnerability private until the fix is released.

## Scope

In scope: the PHP API (`src/`), the portal (`portal/`), the MCP server and its OAuth
routes, the Docker image (`docker/`) and the installation wizard.

Out of scope:

- vulnerabilities of woob or of the bank websites (report them to
  [woob](https://gitlab.com/woob/woob));
- an instance exposed without HTTPS, or a configuration that ignores the
  [security documentation](https://carbure.org/securite/);
- attacks that need an administrator account or access to the server (`data/`);
- denial of service by volume, and reports of scanners without a demonstrated impact.

## Security measures

The security model (JWT, roles, account lockout, security headers, secrets) is described
in the [documentation](https://carbure.org/securite/). Each pull request is
checked for committed secrets (gitleaks) and vulnerable dependencies (Trivy), and the
published Docker image is scanned every week.
