# Socialize documentation

**Socialize** is a self-hosted photo and video social network compatible with the fediverse (Mastodon, Pixelfed…)
through ActivityPub. This wiki explains how to install, configure, run and use it.

> 🇫🇷 La documentation existe aussi [en français](Accueil).

## Getting started

| Page | What you will find |
| --- | --- |
| [Installation](Installation) | One-line installer, Docker, Docker Compose, MariaDB, reverse proxy |
| [Configuration](Configuration) | Every environment variable, e-mail, registrations, workers |
| [Upgrade and Backup](Upgrade-and-Backup) | Upgrading, backing up and restoring an instance |

## Using Socialize

| Page | What you will find |
| --- | --- |
| [User Guide](User-Guide) | Posts, stories, audience groups, mentions, music, notifications, themes |
| [Administration](Administration) | Accounts, invitations, permissions, instance block |
| [Federation](Federation) | How Socialize talks to Mastodon, Pixelfed and the fediverse |
| [Mastodon Sign-In](Mastodon-Sign-In) | Letting people use Socialize with their Mastodon account |
| [Integrations](Integrations) | Instagram import and crossposting, Immich, Synology Photos, Openverse |

## Reference

| Page | What you will find |
| --- | --- |
| [Console Commands](Console-Commands) | Every `app:*` command |
| [Development](Development) | Local environment, tests, quality tools, architecture |
| [FAQ](FAQ) | Troubleshooting and frequent questions |

## At a glance

- One Docker image (FrankenPHP: Caddy + PHP 8.5) with automatic HTTPS, two volumes: `/data` and `/media`.
- SQLite by default, MariaDB or MySQL for larger instances.
- Photos converted to WebP with libvips, videos to H.264 MP4 with ffmpeg, in the background.
- Originals keep their metadata privately; public copies never contain EXIF or GPS data.
- Licensed under the [AGPL-3.0](https://github.com/sadjo/socialize/blob/main/LICENSE).
