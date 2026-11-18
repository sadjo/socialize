# Administration

The first account created on an instance is an administrator. Administrators find **Administration** in the menu.

## Accounts

*Administration › Comptes* lists the accounts of the instance, administrators flagged, and the signed-in Mastodon
accounts.

- **Create an account**: email, username, display name, password (no minimum length here) and permissions.
- **Permissions**, granted or removed account by account:

| Permission | Allows |
| --- | --- |
| *Publication* | Posting, creating stories, posting from Immich and Synology Photos |
| *Import Instagram* | Importing an Instagram archive (with *Publication*) |

A new account has no permission until an administrator grants it; an administrator has both. You are notified of each
new account.

- **Password**: choose a new one for an account that forgot it, then pass it on; its devices are signed out.
- **Picture and cover** of an account, to replace if needed.
- **Delete** an account, or revoke the access of a signed-in Mastodon account.
- **Date of a post**: editable from the post itself.

## Invitations

*Administration › Invitations* creates a single-use sign-up link, valid for 7 days, sent by email or copied to pass it
on otherwise. It works even when sign-ups are closed; a pending invitation can be revoked.

## Instance block

*Administration › Instance* edits the block shown next to the feeds: a title and Markdown text, with an editing toolbar
(bold, links, lists, images). It suits a welcome note, the instance rules or a contact.

## Server settings

These choices are made in the instance configuration (environment file), then applied on restart:

- `REGISTRATION`: `invite` (by invitation, the default), `open` or `closed`;
- `HOME_LOGIN_ONLY=1`: hide everything from signed-out visitors;
- `MASTODON_LOGIN`: Mastodon sign-in, open to any server or limited to a list.

## From the console

Everything can also be done from the command line, for example:

```bash
docker compose exec app php bin/console app:user:create alice@example.org alice --admin --name="Alice"
printf '%s' 'new-password' | docker compose exec -T app php bin/console app:user:password alice --password-stdin
docker compose exec app php bin/console app:federation:refresh alice --essai
```

The full list is in the instance documentation (*Console Commands*).
