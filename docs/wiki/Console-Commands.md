# Console Commands

Run commands inside the container:

```bash
docker compose exec app php bin/console <command>
# with docker run:
docker exec -it socialize php bin/console <command>
```

## Accounts

| Command | Description |
| --- | --- |
| `app:user:create <email> <username>` | Create an account. Options: `--admin`, `--publisher`, `--instagram-import` (with `--publisher`), `--name="Display name"`, `--password-stdin` |
| `app:user:password <email-or-username>` | Change a password (prompted twice, or `--password-stdin`) |

```bash
printf '%s' 'S3cret!' | docker compose exec -T app php bin/console app:user:create bob@example.org bob --publisher --password-stdin
```

## Instagram

| Command | Description |
| --- | --- |
| `app:import:instagram <account> <zip>...` | Import an archive already on the server. Options: `--visibility=public_quiet\|public\|followers\|private` (default `public_quiet`), `--today` (publish with today's date), `--without-stories`, `--group=<name>` (audience group for followers posts) |
| `app:import:announce <account>` | Announce to fediverse followers the posts imported quietly, oldest first. Options: `--dry-run`, `--pause=1` (seconds) |

## Federation

| Command | Description |
| --- | --- |
| `app:federation:resync <post-url-or-id>` | Withdraw old copies of a post from the fediverse, then send it again if visible. For a deleted post: `--compte=<account>` and optionally `--revisions=<n>` |
| `app:federation:reorder <account>` | Send the posts of an account again in chronological order. Options: `--depuis=YYYY-MM-DD`, `--pause=5`, `--essai` (dry run) |
| `app:federation:retract-local-only` | Withdraw from the fediverse unlisted and group posts federated by older versions. `--simulation` lists them |

## Media

| Command | Description |
| --- | --- |
| `app:media:complete-sizes` | Build the missing responsive sizes (640/960 px images, 240/360/720 px grid thumbnails, video posters) of media published by older versions. Safe to run again |

## Development

| Command | Description |
| --- | --- |
| `app:demo:seed` | Demo accounts, posts and stories (refused in production). Password: `demo-socialize` |

## Useful Symfony commands

| Command | Description |
| --- | --- |
| `doctrine:migrations:status` | Database migration status (migrations run automatically at startup) |
| `messenger:stats` | Number of pending messages per queue |
| `messenger:failed:show` / `messenger:failed:retry` | Inspect and retry failed background messages |
| `debug:router` | List the routes of the application |
