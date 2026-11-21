# Integrations

## Instagram import

Accounts with the **Instagram import** permission can bring their whole Instagram history into Socialize
(*Import* in the menu).

1. In Instagram: *Accounts Center › Your information and permissions › Download your information*, choose
   **JSON** format, all-time range, and at least *Content* (posts, stories). Instagram sends one or several `.zip`
   files.
2. In Socialize: upload the archive. Large archives (up to 20 GB, one or several parts) are sent in 50 MB chunks
   and the upload **resumes** after an interruption.
3. The archive is analysed in the background: posts, archived posts and stories. Direct messages are never read.
4. **Review** (*Choix*): pick the visibility of the whole import, then adjust each post (another visibility,
   *Archiver*, or *Ne pas importer*). Optionally choose a default audience group.
5. Import: original dates, carousels in order, EXIF data written back into the originals, archived posts in your
   archive, stories in your story archive. Re-running an import never creates duplicates. Videos are converted
   once all items are imported.

By default (`public_quiet`), imported public posts are **not announced** to the fediverse, to avoid flooding your
followers. You can announce them later with `app:import:announce` (see [Console Commands](Console-Commands)).
`@account` mentions in imported captions link to the Instagram profiles.

An archive already on the server can be imported from the console:

```bash
docker compose exec app php bin/console app:import:instagram alice /media/instagram-1.zip /media/instagram-2.zip
```

## Crossposting to Instagram

*Réglages › Instagram*: an account allowed to publish connects an Instagram **professional** account (Creator or
Business — required by the Instagram API). Rules choose for which visibilities *Publier aussi sur Instagram* is
checked by default (and whether they apply to stories); the box can be changed for each post.

Publishing happens in the background once the media are ready: photos converted to JPEG and letterboxed to an
accepted ratio (never cropped), a single video as a Reel (3 s to 15 min), carousels up to 20 media, stories. The
status is shown on the post, with *Réessayer* (retry) on failure; a post already online can also be sent to
Instagram afterwards. When the application has the `instagram_manage_contents` permission, deleting a post here
deletes its Instagram copy too.

### Meta application

Create an application on [developers.facebook.com](https://developers.facebook.com) (type *Business*). Each
publishing account enters its own in *Réglages › Instagram › Application Meta* (app id, secret — encrypted and never
displayed again — and configuration id if any). The instance can provide a default one with `INSTAGRAM_APP_ID`,
`INSTAGRAM_APP_SECRET` and `INSTAGRAM_CONFIG_ID`.

- **Recent applications ("unified login")**: use case *Manage messaging and content on Instagram*, linked business
  portfolio, a *Facebook Login for Business* configuration with a system user token (expiration *Never*), the
  *Instagram account* asset and the `instagram_basic` and `instagram_content_publish` permissions (plus
  `instagram_manage_contents` for deletions). Enter the app id, its secret and the configuration id, and declare
  `https://<your-instance>/reglages/instagram/retour` as a valid OAuth redirect URI.
- **Older applications ("Instagram login")**: Instagram app id and secret, same redirect URI, empty configuration
  id. Until Meta reviews the application, only Instagram accounts added as **testers** can connect.

Without a Meta application, or for a personal Instagram account, **Partager vers Instagram** (post menu, *Mes
stories*) opens the phone's share sheet with the photos as JPEG and the videos, and copies the caption; on a computer
the files are downloaded.

## Immich

[Immich](https://immich.app) is a self-hosted photo library. *Réglages › Immich*: enter the server address and an API
key with these permissions: `user.read`, `asset.read`, `asset.view`, `asset.download`, `asset.statistics`,
`album.read`, `album.create`, `albumAsset.create`. The key is encrypted and never displayed again.

In the compose screen, the **Immich** tab shows your library by day and place with infinite scroll, an album filter
and a date picker (*Aller au*). Click a thumbnail to select it, the magnifier for a large preview (videos play).
Immich and device media can be mixed in the same post; caption and location are prefilled. On publishing, the
originals are downloaded with their EXIF data (date, place, GPS, lens). Published media can be added to an Immich
album *Publié sur Socialize*. Live Photos are supported: still thumbnail, animated preview, choice of version.

The Immich server must be reachable from the Socialize server.

## Synology Photos

*Réglages › Synology Photos*: enter the address of your Synology server (DSM 7), your DSM username and password,
and the one-time code if two-factor authentication is on. They open a session on the server; only that session is
kept, encrypted — never the password. The compose screen then shows a **Synology Photos** tab with the personal
space, the shared space and albums, with the same preview and selection features as Immich, Live Photos and
multi-file items included.

The session lasts as long as it is used (DSM › Control Panel › Security › Logout timer, up to 45 days of
inactivity); when it expires, Socialize offers to reconnect. The server must be reachable from Socialize
(QuickConnect addresses are not supported). Synology does not document the Photos API: a DSM update may change it.

Both libraries also provide the cover image of a story highlight (*Mes stories › Modifier*), in addition to the device.

## Openverse (music)

Music search uses the public [Openverse](https://openverse.org) API — no configuration needed. Previews are relayed
by your server (the browser never contacts the provider) and nothing is stored until you publish: the chosen track
is then copied once to the instance as AAC and shared by every post that uses it. Credits (title, artist, license,
source) are shown under the author and included in the ActivityPub note.
