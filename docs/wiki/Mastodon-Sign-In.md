# Mastodon Sign-In

People who already have a Mastodon account can use Socialize **without creating a local account**: they sign in
with Mastodon (OAuth) and Socialize acts on their behalf through the API of their own server.

## Enabling it

```bash
MASTODON_LOGIN=open                              # any Mastodon server
MASTODON_LOGIN=mastodon.social,piaille.fr        # or only these servers
MASTODON_SIGNUP=mastodon.social                  # optional: create a Mastodon account from the login screen
```

- If `mastodon.social` is allowed, a one-click button is shown; other servers are typed in a field.
- Socialize registers an OAuth application on each server the first time someone signs in from it.
- With `MASTODON_SIGNUP`, the login screen offers to create an account on that server (it must also be allowed by
  `MASTODON_LOGIN`). New accounts are private (approve followers) by default. Account creation is rate-limited to
  3 per hour per IP address.

## What a Mastodon account can do

- **Profile**: shows the person's Mastodon posts; profile edits are made on their Mastodon server.
- **Feed**: posts of the accounts they follow that the instance knows, local accounts included.
- **Stories**: they see the stories of the local accounts they follow, with the same visibility rules (followers,
  audience groups) as any remote follower.
- **Likes, comments and follows** go through the Mastodon API of their server, which then federates them.
- **Publishing**: photos and videos posted from Socialize are published **on their Mastodon account**.
- **Instagram import**: if an administrator grants it, they can import their Instagram archive into their Mastodon
  account (*Administration › Comptes › Comptes Mastodon*).

Socialize never signs anything on behalf of a Mastodon account (it does not have its keys): actions are performed by
their server, with the access token granted at sign-in, encrypted in the database.

## Administration

Connected Mastodon accounts are listed in *Administration › Comptes*. An administrator can revoke the access of an
account (its token is deleted) and can replace its picture or cover on Socialize only.
