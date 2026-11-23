# Development

## Requirements

Only **Docker** and **make**. PHP, Composer and all tools run in the container.

```bash
git clone https://github.com/sadjo/socialize.git
cd socialize
make up            # http://localhost:8080 — HTTP_PORT=8099 make up for another port
make help          # every command
```

| Command | Description |
| --- | --- |
| `make up` / `make down` | Start / stop the development environment |
| `make logs`, `make sh` | Follow the logs, open a shell in the container |
| `make test` | PHPUnit in parallel (ParaTest, one process per core; `p=4` to set the number) |
| `make test-serie` | PHPUnit alone, one test after another (`f=<filter>` to pick some) |
| `make quality` | PHP-CS-Fixer, Rector (dry run), PHPStan (max level), Deptrac — like the CI |
| `make fix` | Apply Rector and the coding style |
| `make console c="…"` | Run a Symfony command, e.g. `make console c="app:demo:seed"` |
| `make composer c="…"` | Run Composer, e.g. `make composer c="require symfony/uid"` |
| `make prod` | Build the production image |
| `make immich-up` / `immich-seed` / `immich-down` | Local Immich test instance |

The code is mounted in the container and FrankenPHP reloads changed files. Xdebug is installed but disabled:
`XDEBUG_MODE=debug make up`. Each environment has its own SQLite database (`var/app_dev.db`, `var/app_test.db`), so
tests never wipe your development data. `make console c="app:demo:seed"` creates demo accounts (`camille`, `hugo`,
`ines.lmb`, password `demo-socialize`).

## Architecture

```
src/
  Core/          accounts, posts, stories, social graph, notifications (the shared kernel)
  Media/         storage, image and video processing, photo library abstraction
  Federation/    ActivityPub: actors, signatures, inbox, delivery
  Import/        Instagram archive import
  Crosspost/     publishing to Instagram (Meta Graph API)
  Integration/   Immich, Synology Photos, Openverse, Mastodon
  Web/           controllers, forms, Twig components, console commands
  Admin/         administration area
```

Each module follows `Domain` / `Application` / `Infrastructure`. **Deptrac** (`deptrac.yaml`) enforces the
boundaries: business modules depend only on `Core` and `Media`; `Core` emits domain events that other modules
listen to (federation, crossposting, notifications).

- **Persistence**: Doctrine ORM, portable between SQLite and MySQL/MariaDB — no engine-specific SQL. Schema changes
  go through migrations: `make console c="make:migration"`.
- **Interface**: Twig, Symfony UX (Turbo, Stimulus, Live Components) and AssetMapper; no Node build.
- **Background work**: Symfony Messenger (Doctrine transport, queues `federation`, `async`, `media`) and Symfony
  Scheduler.
- **Media**: libvips (through FFI) for images, ffmpeg for videos, Flysystem storage.

## Tests

PHPUnit tests extend `WebTestCase` (HTTP) or `KernelTestCase` (services). External services are replaced by fakes:
a fake fediverse instance with real keys, fake Immich, Synology Photos, Openverse and Instagram Graph API. The suite
fails on any deprecation. The CI runs it on SQLite and MariaDB.

```bash
make test
make console c="--env=test doctrine:migrations:migrate -n"   # if the test schema is out of date
```

## Immich test instance

```bash
make immich-up     # Immich on http://localhost:2283
make immich-seed   # admin@socialize.test / socialize-immich, ~20 generated photos, 2 videos, 2 albums
make immich-down   # stop (data kept in the socialize-immich volumes)
```

`make immich-seed` prints the server address as seen from the application (`http://host.docker.internal:2283`) and
a new API key to enter in *Réglages › Immich*.

## Releases

Versions follow `vMAJOR.MINOR.PATCH`. Pushing a tag on `main` runs the *Release* workflow: multi-architecture image
on `ghcr.io/sadjo/socialize` (`vX.Y.Z` and `latest`) and a GitHub release built from the tag message.

See also [CONTRIBUTING.md](https://github.com/sadjo/socialize/blob/main/CONTRIBUTING.md).
