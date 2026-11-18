# Administration

The first account created on an instance is an administrator. Administrators find **Administration** in the menu
(`/admin`).

## Accounts (*Comptes*)

- List of the accounts of the instance, administrators marked.
- **Create an account** by filling in its e-mail, username, display name and password (no minimum length for
  accounts created this way), with its permissions.
- **Permissions**, granted or revoked per account:

| Permission | Allows |
| --- | --- |
| *Publication* | Posting, stories, publishing from Immich and Synology Photos |
| *Import Instagram* | Importing an Instagram archive (requires *Publication*) |

  New accounts have no permission until an administrator grants it. Administrators always have both.
- **Change the password** of an account, **change its profile picture or cover**.
- **Delete** an account, or revoke the access of a Mastodon account connected to the instance.
- **Edit the publication date** of a post (from the post itself).

## Invitations

With `REGISTRATION=invite` (the default), people join through invitations: *Administration › Invitations* creates a
single-use link valid for 7 days, sent by e-mail (if `MAILER_DSN` is configured) or copied and shared by any other
means. An invitation link works even when registrations are closed. Only a hash of the token is stored; pending
invitations can be revoked.

## Instance block

*Administration › Instance* edits the block shown next to the feeds: a title and a Markdown text written with a
toolbar (bold, links, lists, images uploaded on the instance). Use it for a welcome message, rules or contact
information.

## From the console

Everything can also be done from the command line, e.g. to bootstrap a closed instance:

```bash
docker compose exec app php bin/console app:user:create alice@example.org alice --admin --name="Alice"
printf '%s' 'new-password' | docker compose exec -T app php bin/console app:user:password alice --password-stdin
```

See [Console Commands](Console-Commands).

## Moderation tips

- Keep `REGISTRATION=invite` or `closed` on a private instance, and `HOME_LOGIN_ONLY=1` to hide everything from
  visitors.
- `MASTODON_LOGIN` can be restricted to a list of trusted servers.
- The `federation` log channel (`docker compose logs app`) records every delivery and received activity, with the
  reason when an exchange is refused.
