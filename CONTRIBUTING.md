# Contributing to Socialize

Thank you for your interest in Socialize! Bug reports, ideas, documentation and code are all welcome.

## Before you start

- Search the [issues](https://github.com/sadjo/socialize/issues) to see whether your topic is already
  being discussed. For a larger change, open an issue first so we can agree on the approach.
- Security vulnerabilities must **not** be reported in public issues: see [SECURITY.md](SECURITY.md).
- By contributing, you agree that your contributions are licensed under the [AGPL-3.0](LICENSE).

## Development environment

Only Docker and `make` are required: PHP, Composer and every tool run inside the container.

```bash
git clone https://github.com/sadjo/socialize.git
cd socialize
make up                             # http://localhost:8080 (HTTP_PORT=8099 make up for another port)
make console c="app:demo:seed"      # demo accounts and posts
make help                           # every command
```

The code is mounted in the container and FrankenPHP reloads changed files. Xdebug is installed but disabled:
`XDEBUG_MODE=debug make up` enables it. An Immich test instance is available with `make immich-up` and
`make immich-seed` (see [Development](https://github.com/sadjo/socialize/wiki/Development)).

## Architecture in a nutshell

- Modules live in `src/<Module>/{Domain,Application,Infrastructure}`; Deptrac (`deptrac.yaml`) enforces their
  boundaries: business modules only depend on `Core` and `Media`.
- Persistence uses Doctrine ORM and must stay portable between SQLite and MySQL/MariaDB: no engine-specific SQL.
  Schema changes always go through a migration (`make console c="make:migration"`).
- The interface is server-rendered with Twig and Symfony UX (Turbo, Stimulus, Live Components) and AssetMapper:
  there is no Node build step.
- Background work goes through Symfony Messenger (Doctrine transport) and Symfony Scheduler.
- Prefer Symfony components and PHP attributes (`#[Route]`, `#[AsCommand]`, `#[AsMessageHandler]`…) over hand-written
  infrastructure and YAML configuration.

## Quality gates

Every pull request must pass:

```bash
make quality   # PHP-CS-Fixer (@Symfony), Rector, PHPStan (max level), Deptrac, Symfony linters
make test      # PHPUnit (fails on any deprecation)
```

`make fix` applies Rector and the coding style automatically. A feature is complete when it has a test that
exercises it the way a caller would: an HTTP request for a controller, a service call for a service.

## Git workflow

- Branch from an up-to-date `main`: `feature/<topic>`, `fix/<topic>`, `docs/<topic>` or `chore/<topic>`.
- Write commit messages following [Conventional Commits](https://www.conventionalcommits.org/):
  `feat(federation): …`, `fix(video): …`, `docs: …`, `test: …`, `refactor: …`, `ci: …`.
  Keep the subject under 72 characters, in the imperative mood; explain the *why* in the body when useful.
- Open a pull request against `main` and fill in the template. Pull requests are merged with a merge commit.
- Releases are tagged `vMAJOR.MINOR.PATCH` on `main`; each tag publishes a container image and a GitHub release.

## Translations

The interface is currently in French. Translations are welcome: strings live in the Twig templates and PHP code
for now; please open an issue to coordinate the extraction work before starting.
