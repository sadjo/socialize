# Security Policy

## Supported versions

Security fixes are released for the latest minor version of Socialize. Always run the most recent `vX.Y.Z` image.

| Version | Supported |
| ------- | --------- |
| latest `0.x` release | ✅ |
| older releases | ❌ |

## Reporting a vulnerability

Please **do not** open a public issue. Report vulnerabilities privately through
[GitHub security advisories](https://github.com/sadjo/socialize/security/advisories/new).

Include as much information as possible: affected version, configuration, steps to reproduce, impact and, if you
have one, a suggested fix. You will receive an acknowledgement within a few days. Once a fix is available, a new
release is published and the advisory is disclosed, crediting you unless you prefer to stay anonymous.

## Hardening notes

- `APP_SECRET` encrypts the private keys of the accounts and the tokens of connected services: keep it secret and
  never change it on a running instance.
- Originals and their metadata are never served publicly; public copies are stripped of EXIF and GPS data.
- Outgoing federation requests cannot reach private networks unless `FEDERATION_ALLOW_PRIVATE_NETWORKS=1`
  (intended for local tests only).
