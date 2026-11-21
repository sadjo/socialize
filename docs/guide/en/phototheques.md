# Immich and Synology photo libraries

A connected photo library lets you pick the media of a post straight from your albums. Originals are copied with their
metadata (date, location, camera) when posting.

## Immich

*Réglages › Immich*: address of the Immich server and an API key, created in Immich (*Account settings › API keys*)
with the `user.read`, `asset.read`, `asset.view`, `asset.download`, `asset.statistics`, `album.read`, `album.create`
and `albumAsset.create` permissions. The key is encrypted and never shown again.

When creating a post, the **Immich** tab shows the library by day and place, with an album filter and *Aller au* to
jump to a date. A click selects a thumbnail, the magnifier opens a large preview. Posted media can join an Immich album
*Publié sur Socialize*. Live photos are supported.

## Synology Photos

*Réglages › Synology*: address of the Synology server (DSM 7), DSM username and password, and a one-time code if
two-factor authentication is on. Only the opened session is kept, encrypted, never the password.

The **Synology Photos** tab offers the personal space, the shared space and the albums, with the same previews and
selections as Immich. When the session expires, Socialize offers to sign in again.

## Mixing sources

Media from the device, Immich and Synology Photos mix in a single post; caption and location are prefilled from the
library when it has them. The same tabs are used to pick the cover image of a
[highlight](stories.md#featuring-a-story).
