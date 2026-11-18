# Federation

Socialize implements [ActivityPub](https://www.w3.org/TR/activitypub/) and interoperates with Mastodon, Pixelfed and
other fediverse software.

## What is supported

- **Discovery**: WebFinger (`@name@domain`), NodeInfo 2.1, host-meta; a `Person` actor per account and an instance
  actor.
- **Security**: HTTP Signatures (draft-cavage, rsa-sha256) on every outgoing and incoming request, `Digest` and
  `Date` checks, signer and actor compared, keys refreshed when they change. Each account has its own RSA key pair;
  private keys are encrypted with `APP_SECRET`. Outgoing requests never reach private networks.
- **Objects**: posts are `Note` objects with their photos and videos (alt text, blurhash, video thumbnails),
  hashtags, mentions, content warning and music credit; comments are replies.
- **Activities**: Follow / Accept / Reject / Undo, Like, Create, Update, Delete — sent through background workers
  to shared inboxes with retries.
- **Backfill**: when a remote account accepts a follow, its photo and video posts of the last six months
  (50 at most) are imported.

## Visibility and the fediverse

Only **public** and **followers-only** posts are sent to the fediverse. Unlisted posts and group posts stay on the
instance.

Mastodon never changes the visibility or the recipients of a post it already received. When an author changes them,
Socialize withdraws the post from the fediverse (`Delete`) and sends it again under a new id (`/p/…/v2`, `/v3`…).
On Mastodon, the new version starts without the likes and boosts of the previous one; those already received stay
on Socialize. Editing a post without changing its audience sends a regular `Update`.

## The domain name

`DEFAULT_URI` is the base of every ActivityPub address. Choose it before federating and never change it: remote
servers identify your accounts and posts by these addresses.

## Maintenance commands

```bash
# Withdraw stale copies of a post from remote instances, then send it again if it is still visible
bin/console app:federation:resync https://photos.example.org/p/1Ch1rCiDZ7zSfRdPMRVyPC
# …for a deleted post, give the account that published it
bin/console app:federation:resync https://photos.example.org/p/1Ch1rCiDZ7zSfRdPMRVyPC/v4 --compte=alice

# Send an account's posts again, from the oldest to the newest, to restore the chronological order on Mastodon
bin/console app:federation:reorder alice --essai               # dry run
bin/console app:federation:reorder alice --depuis=2025-09-05   # since a date, 5 s pause between posts

# Withdraw unlisted and group posts that were federated by older versions
bin/console app:federation:retract-local-only --simulation
```

Run them with `docker compose exec app php bin/console …`. See [Console Commands](Console-Commands).

## Troubleshooting

- **A remote search returns nothing**: check that your instance is reachable over HTTPS from the Internet and that
  `DEFAULT_URI` matches the public address. Servers with *authorized fetch* (such as mastodon.social) require signed
  requests, which Socialize does through its instance actor.
- **A post does not appear on Mastodon**: look at the `federation` log channel (`docker compose logs app | grep
  federation`) for the HTTP status returned by the remote server.
- **Testing locally**: `FEDERATION_ALLOW_PRIVATE_NETWORKS=1` allows federation with private addresses. Never enable
  it in production.
