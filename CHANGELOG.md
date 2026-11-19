# Changelog

All notable changes to Socialize are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.9.31] - 2026-11-19

No black screen before videos.

### Fixed

- A feed video no longer shows a black screen while it loads: the player stays invisible over the poster and appears
  at its first frame actually displayed (feed)

### Changed

- The video player is prepared as soon as the video is 25% visible, so loading starts earlier; it still plays at 60%
  and is removed off screen, one or two players at most (feed)

## [0.9.30] - 2026-11-19

Mastodon accounts: followers and notifications.

### Added

- A Mastodon account signed in here accepts or refuses follow requests coming from here, and removes followers,
  through its Mastodon server, which tells the follower over federation (Mastodon)
- Mastodon accounts signed in here get the same notifications as local accounts (likes, comments, mentions, follow
  requests) and find them in the menu; device notifications remain those of their Mastodon app (notifications)

### Changed

- The "approves followers" setting of remote accounts is read from their actor (manuallyApprovesFollowers); an Accept
  or Reject received from the followed account clears the notification of the request (federation)
- The administrators' notification of a first Mastodon sign-in leads to the account's profile, without asking to
  grant rights (administration)

## [0.9.29] - 2026-11-19

Videos on iPhone.

### Fixed

- Videos of the feed no longer stay black on iPhone: the page holds no video player any more, only the poster and the
  address of the video; the player is created when the video is visible and removed as soon as it is not, so there is
  never more than one at a time (Safari on iPhone left videos black beyond a few players, and three pages of the feed
  held more than a hundred) (feed)

### Changed

- The media filter of lists is a translucent veil on videos too: no CSS filter left in lists (feed)

## [0.9.28] - 2026-11-19

Screens refreshed after forms again.

### Fixed

- After signing in, following a suggested account or accepting a follow request, the screen stayed unchanged: the
  feed and the profile answered the redirected form with the "next page" stream instead of the page. The next page is
  now requested with its own header, and every other request gets the full page (feed, profile)

## [0.9.27] - 2026-11-19

Reliable post lists on mobile.

### Fixed

- Videos and photos no longer turn black after scrolling the feed on iPhone: list photos are softened by a
  translucent veil instead of a CSS filter, and buttons over media no longer blur what is behind them (one graphics
  layer less per image) (feed)
- A video released far from the screen gets its poster and preloading back when it returns, instead of staying black
  (feed)

### Changed

- Feeds and profile grids are a single flat list: the next page is requested 1.5 screens ahead as a Turbo Stream,
  one request at a time, with a "Retry" button when the network drops and an automatic retry once it is back
  (feed, profile)
- Next pages only render their posts: no suggestions, stories or profile header computed for them (feed, profile)

## [0.9.26] - 2026-11-18

Photos on iPhone.

### Fixed

- After a few pages of the feed, Safari on iPhone no longer showed photos (grey frames): only the photo shown in a
  carousel and its neighbours are loaded, and posts more than two screens away release their photos and videos,
  loaded again when they come back (feed)
- Very dense phone screens (3x) get the copy meant for 2x screens: half the memory and data, with no visible
  difference (feed)

### Changed

- New videos are capped at 3.5 Mbit/s, so they start faster on mobile networks (media)

## [0.9.25] - 2026-11-18

Faster feeds.

### Added

- Images received from the fediverse (and the posters of their videos) are copied to the instance in the background,
  in the same sizes as local photos, instead of being loaded full size from their original server; the original
  address is still used until the copy is made or if it fails. Videos stay on their instance (federation)
- `app:media:cache-remote` queues the copies of media received before this version (console)

### Changed

- The home feed reads the posts of the followed accounts through an index instead of scanning the whole posts table,
  fediverse posts included (feed)
- Comment previews of a feed page are loaded with a single query (feed)
- Compiled assets are cached by the browser for a year (`immutable`), so opening the app no longer revalidates every
  script (deployment)
- Stimulus controllers only used by forms, stories, settings, administration and imports are loaded on demand (ui)

### Fixed

- The *Instance* block next to the feeds is read again on every request in worker mode: a change made in the
  administration is no longer hidden by threads that read the old text (admin)

## [0.9.24] - 2026-11-18

User guide.

### Added

- User guide in English and French, written once in `docs/guide` and published in the app (`/aide`, readable without
  an account, administration chapter for administrators), on the wiki and on the website (`guide/`) (docs)
- Discreet *Aide* link under the menu and small *?* next to page titles, leading to the matching part of the guide (ui)
- `make guide` builds the wiki and website pages of the guide locally (docs)

### Changed

- The *User Guide* and *Guide d'utilisation* wiki pages are now generated from `docs/guide` (docs)

## [0.9.23] - 2026-11-18

Test fix.

### Fixed

- The test of web addresses without `http(s)://` uses a neutral domain: it failed in the CI of the exported
  repository (tests)

## [0.9.22] - 2026-11-18

Scrolling tabs on desktop.

### Fixed

- Settings and administration tabs that do not fit are reachable with a mouse: arrows on the edges when tabs are
  hidden, and the mouse wheel scrolls the strip (ui)

## [0.9.21] - 2026-11-18

Password change.

### Added

- *Réglages › Mot de passe*: people change their own password with the current one; other sessions and remembered
  devices are signed out, the current one stays signed in (settings)

## [0.9.20] - 2026-11-18

Follow requests.

### Added

- Profile setting to approve follow requests oneself: new followers, local or from the fediverse, wait for approval
  (`manuallyApprovesFollowers`); a *Demandes* page to accept or refuse them, notifications in the app and on devices;
  unticking the setting accepts the pending requests (social)

## [0.9.19] - 2026-11-18

Links in captions.

### Added

- Web addresses in captions, comments and bios become links, with or without `http(s)://`, shortened for display
  and federated in the Mastodon format (posts)
- `app:federation:refresh` sends the current content of an account's posts to the fediverse under their id, keeping
  their reactions; by default only posts whose caption contains a web address (federation)

### Fixed

- A `#fragment` or an `@name` inside a web address is no longer taken for a hashtag or a mention (posts)

## [0.9.18] - 2026-11-18

Composer lock file fix.

### Fixed

- `composer.lock` is in sync with `composer.json`: `composer validate --strict` passes again (ci)

## [0.9.17] - 2026-11-18

Open source release.

### Added

- Open source release under the AGPL-3.0, with a one-line installer (`install.sh`)
- Bilingual documentation (English and French) in the wiki, website with screenshots and demo video
- GitHub Actions: CI, multi-architecture release images on GHCR, website and wiki publishing
- Contributing guide, code of conduct, security policy, issue and pull request templates

### Fixed

- Restore border-box sizing on every element: the full-screen viewer no longer overflows the screen (ui)
- Pass SortDirection instead of 'ASC' / 'DESC' strings to Doctrine (db)

## [0.9.16] - 2026-11-18

Media filter and responsive image sizes.

### Added

- Per-user media filter in feeds and grids (settings)

### Changed

- Media filter slightly darkens instead of brightening (settings)
- Responsive image sizes to reduce shimmering in feeds and grids (media)

## [0.9.15] - 2026-11-17

Per-user interface themes.

### Added

- Per-user interface theme (settings)
- Instant preview of the selected theme (settings)
- Automatic theme uses Night violet in dark mode (theme)

### Changed

- Remove the colored side border on primary buttons (buttons)
- Plain outline around theme previews (settings)

## [0.9.14] - 2026-11-17

Readable Instagram status in the story viewer.

### Fixed

- Readable Instagram status in the viewer (stories)

## [0.9.13] - 2026-11-17

Wide menu on iPad, Instagram status of stories.

### Added

- Wide menu from 1024 px (iPad landscape) (ui)
- Instagram delivery status for the author (stories)

## [0.9.12] - 2026-11-17

Landscape media frame on iPad.

### Fixed

- Full-width media frame in landscape on iPad (feed)

## [0.9.11] - 2026-11-17

Smoother scrolling on iPad.

### Fixed

- No jumping posts while scrolling on iPad (feed)

## [0.9.10] - 2026-11-17

Maintenance release.

## [0.9.9] - 2026-11-17

Mastodon account mentions.

### Added

- A second "@" switches to fediverse accounts (mentions)
- Suggest the typed Mastodon address (mentions)

## [0.9.8] - 2026-11-17

Instagram verification removed, documentation.

### Removed

- Drop Instagram account verification (mentions)

### Documentation

- Mentions, profile, instance block and notifications

## [0.9.7] - 2026-11-17

Instagram mention verification fixes.

### Fixed

- Resume after reconnecting, log refusals, diagnostics (mentions)

## [0.9.6] - 2026-11-17

Mentions in captions.

### Added

- Local, fediverse and Instagram mentions in captions (mentions)
- Suggestions as soon as "@" is typed (mentions)
- Verify Instagram accounts through the unified login (mentions)
- Network badges, dismiss until Ctrl+Space (mentions)

### Changed

- Monochrome icons (mentions)
- Mastodon elephant for local and fediverse accounts (mentions)

## [0.9.5] - 2026-11-17

Instance block and new account notifications.

### Added

- Editable Markdown instance block with toolbar (admin)
- Notify administrators of new accounts (notifications)

## [0.9.4] - 2026-11-17

Profile cover and menu.

### Added

- Cover image, admins can change an account picture (profile)
- Crop the picture, repositionable and shorter cover (profile)
- Home becomes "Posts" without the feed tab bar (ui)

### Changed

- Move cropping columns to their own migration (db)

## [0.9.3] - 2026-11-16

Fediverse audience rules, account deletion, private Mastodon accounts.

### Added

- Only public and followers-only posts reach the fediverse (federation)
- Delete an account or revoke a Mastodon account's access (admin)
- New Mastodon accounts are private by default (mastodon)

## [0.9.2] - 2026-11-16

One-click mastodon.social and Mastodon account creation.

### Added

- One-click mastodon.social, other servers still possible (mastodon)
- Create a Mastodon account from the login screen (mastodon)

## [0.9.1] - 2026-11-16

Fix the Mastodon sign-in button.

### Fixed

- Submit the login form without Turbo (mastodon)

## [0.9.0] - 2026-11-16

Sign in with a Mastodon account.

### Added

- Sign in with a Mastodon account (mastodon)
- Likes, comments and follows through the Mastodon API (mastodon)
- Publish photos and videos to one's Mastodon account (mastodon)
- Import an Instagram archive into a Mastodon account (mastodon)

### Documentation

- Design note for signing in with a Mastodon account
- Plan Mastodon sign-in (roadmap)
- Make Mastodon sign-in the next milestone (roadmap)

## [0.8.7] - 2026-11-16

Live Photos from Immich and Synology Photos.

### Added

- Live Photos with still thumbnail, animated preview and version choice (libraries)
- Animate Live Photos on hover or long press (libraries)

## [0.8.6] - 2026-11-16

Web Push delivery fix.

### Fixed

- Provide an HTTP client to the push sender (notifications)

## [0.8.5] - 2026-11-16

Web Push notifications.

### Added

- Web Push for posts, stories, likes and comments (notifications)

## [0.8.4] - 2026-11-16

Instagram retry and deletion, public profile, fediverse reordering.

### Added

- Retry, keep the permalink and follow deletions (instagram)
- Public profile without menu, copyable address and "Powered by" (profile)
- Reorder an account's posts chronologically (federation)

### Fixed

- Show default Instagram rules once enabled (settings)
- Editing a post without changing its date no longer re-sends it (federation)
- Accept Live Photos and multi-file items (synology)

## [0.8.3] - 2026-11-16

Unified Meta login, Instagram afterwards, profile grid fix.

### Added

- Publish an existing post afterwards (instagram)
- Link to the connection from compose, app id help (instagram)
- Unified Meta login for recent Meta apps (instagram)

### Fixed

- Flat grid to unblock scrolling on iPhone (profile)

## [0.8.2] - 2026-11-13

Notifications.

### Added

- Notify received likes and comments (notifications)

### Fixed

- Center the initial in the default avatar (likes)

## [0.8.1] - 2026-11-13

Per-account Meta application.

### Added

- Per-account Meta application settings (instagram)

## [0.8.0] - 2026-11-13

Crossposting to Instagram.

### Added

- Remember the crosspost request on posts and stories (crosspost)
- Instagram connection, rules and publishing (crosspost)
- "Share to Instagram" button and documentation (instagram)

### Fixed

- Readable tabs on desktop and mobile (settings)

## [0.7.12] - 2026-11-13

4:5 frame, likes list, Create button, story bar and publication date.

### Added

- 4:5 frame covered by the media for every post (posts)
- Clickable position dots (carousel)
- "Create" button with a choice between post and story (ui)
- Hide the story bar when there is nothing to watch (feed)
- See who liked a post from the counter (posts)
- Editable publication date (admin)

### Fixed

- Reliable double-tap like, videos included (likes)

## [0.7.11] - 2026-11-13

Uncropped media, pending video posts, groups federation and app icon.

### Added

- Show media uncropped with carousel dots below (posts)
- Full photos and videos on desktop (viewer)
- Same 4:5 frame for every post on mobile (posts)
- Hold video posts during conversion, progress on the profile (posts)
- Home screen icon for iPhone, iPad and Android (pwa)

### Fixed

- Federate group membership changes (groups)

## [0.7.10] - 2026-11-12

Fediverse: visibility changes and deletions propagated.

### Added

- App:federation:resync command for stale copies (federation)

### Fixed

- Changing a post audience withdraws and republishes it (federation)

## [0.7.9] - 2026-11-12

Synology Photos and large library previews.

### Added

- Publish from a Synology Photos library (synology)
- Large preview with navigation between media (libraries)
- Thumbnail selects, magnifier opens the preview (libraries)
- Magnifier on selected media (compose)
- Remove a media from the selected media preview (compose)

### Changed

- Shared foundation for external photo libraries (media)

### Fixed

- Reliable infinite scroll (libraries)

## [0.7.8] - 2026-11-12

Administration: change an account password.

### Added

- Change an account password (admin)

## [0.7.7] - 2026-11-12

Publishing and import permissions, music on mobile.

### Added

- Publishing permission granted by administrators (admin)
- Instagram import permission granted by administrators (admin)
- Animated equalizer on the cover during preview (music)

### Fixed

- Keep the accounts and permissions table within the page (admin)
- Instagram import requires the publishing permission (admin)
- Readable results list on mobile (music)

## [0.7.6] - 2026-11-12

Videos: preload the first video of the page.

### Changed

- Preload the first video of the page (video)

## [0.7.5] - 2026-11-12

Navigation: full page loads on iPhone and iPad.

### Fixed

- Full page loads on iPhone and iPad for video autoplay (navigation)

## [0.7.4] - 2026-11-12

Videos: autoplay after Turbo navigation on iPhone.

### Fixed

- Recreate videos after a Turbo navigation to allow autoplay (iPhone) (video)

## [0.7.3] - 2026-11-12

Videos: playback when returning to the home page.

### Fixed

- No playback in Turbo previews nor on removed videos (iPhone) (video)

## [0.7.2] - 2026-11-11

Videos: playback after Turbo navigation in Safari.

### Fixed

- Play after a Turbo navigation in Safari (iPhone) (video)

## [0.7.1] - 2026-11-11

Videos: no black screen when navigating back.

### Fixed

- No black screen when navigating back (iPhone) (video)

## [0.7.0] - 2026-11-11

Audience groups, media viewer and admin-created accounts.

### Added

- Create accounts from the administration area (admin)
- No minimum password length for admin-created accounts (admin)
- Audience groups model, access rules and federation (groups)
- Settings, visibility picker and badges (groups)
- Default and per-post group for Instagram imports (groups)
- Full-screen media viewer for photos and videos (posts)

### Fixed

- Hide arrows on the first and last media (carousel)

## [0.6.8] - 2026-11-11

Full mobile menu.

### Added

- Full menu (logout, search, import, admin, theme) (mobile)

## [0.6.7] - 2026-11-11

Invitations and fixed zoom on mobile.

### Added

- Invitations by e-mail or link (admin)

### Fixed

- Disable page zoom on iPhone (mobile)

## [0.6.6] - 2026-11-11

Videos playable in desktop browsers.

### Fixed

- Advertise converted videos as MP4 (media)

## [0.6.5] - 2026-11-11

Announce imported posts and members-only instances.

### Added

- App:import:announce announces quietly imported posts (import)
- Login-only home page for visitors (HOME_LOGIN_ONLY)
- Members-only instance closes public feeds and sign-up to visitors
- Members-only instance closes search and login page, menu-less profiles

## [0.6.4] - 2026-11-11

Priority queues, dedicated workers and version display.

### Added

- Show the version number at the bottom of the side menu (ui)
- Priority queues, dedicated workers and instance outbox (messenger)

## [0.6.3] - 2026-11-10

Compose project naming.

## [0.6.2] - 2026-11-10

MariaDB as the recommended production database.

## [0.6.1] - 2026-11-10

Full Instagram archive analysis and Instagram mentions.

### Added

- Link mentions in imported posts to Instagram profiles (import)

### Fixed

- Analyse full Instagram archives without running out of memory (import)

## [0.6.0] - 2026-11-10

Immich integration and free music (Openverse).

### Added

- Publish from an Immich library (immich)
- Jump to a date in the library (immich)
- Free music on posts and stories (Openverse) (music)
- Proxied previews without storage, copy on publish (music)
- Search in a centered modal on desktop (music)
- Show cover art in search results (music)
- Favorite tracks, suggested when the search opens (music)

### Changed

- Chain previews without waiting on the session lock (music)
- Rely on the global hidden rule (music)

### Fixed

- Hide the date picker when an album is selected (immich)
- Center every modal dialog in the viewport (ui)
- Keep elements with the hidden attribute hidden (ui)
- Show favorites again when the search is cleared (music)

## [0.5.0] - 2026-11-09

Instagram archive import.

### Added

- Import an Instagram archive (import)

## [0.4.1] - 2026-11-06

Post menu, sharing, comments, followers lists and archives.

### Added

- Post menu, sharing sheet and inline comments (posts)
- Followers and following lists, removal by the owner (profile)
- Archive posts and browse archived stories

### Fixed

- Menu links, spacing, copy feedback and windowed video player (feed)

## [0.4.0] - 2026-11-05

ActivityPub federation with Mastodon and Pixelfed.

### Added

- ActivityPub federation with Mastodon and Pixelfed (federation)
- Edit posts, append media and reorder them (posts)
- Log exchanges with remote instances (federation)
- Add video thumbnails (icon) to attachments (federation)
- Instagram-like video playback and edge-to-edge posts on mobile (feed)
- Backfill recent posts when following a remote account (federation)
- Scrollable feed opened on the selected post, remote video thumbnails (profile)

### Fixed

- Enable SQLite foreign keys only on SQLite (db)
- Apply the foreign keys middleware closest to the driver (db)
- Run SQLite migrations without foreign keys (db)
- Resolve the instance actor through WebFinger (federation)
- Use a new Note id after a post was withdrawn (federation)
- Add a silent audio track to videos without sound (media)

## [0.3.0] - 2026-11-04

Stories, story viewer, highlights and archives.

### Added

- Stories, story viewer, highlights and archives
- Change passwords and read them from standard input (console)

### Fixed

- Restore the preview of selected media (compose)
- Fix media previews and allow login with a username (compose)

## [0.2.0] - 2026-11-03

Accounts, photo and video posts, feed and profile.

### Added

- Accounts, photo and video posts, feed and profile
- Create the administrator from the command line (console)

## [0.1.0] - 2026-11-02

Project foundation.

[Unreleased]: https://github.com/sadjo/socialize/compare/v0.9.24...HEAD
[0.9.24]: https://github.com/sadjo/socialize/compare/v0.9.23...v0.9.24
[0.9.23]: https://github.com/sadjo/socialize/compare/v0.9.22...v0.9.23
[0.9.22]: https://github.com/sadjo/socialize/compare/v0.9.21...v0.9.22
[0.9.21]: https://github.com/sadjo/socialize/compare/v0.9.20...v0.9.21
[0.9.20]: https://github.com/sadjo/socialize/compare/v0.9.19...v0.9.20
[0.9.19]: https://github.com/sadjo/socialize/compare/v0.9.18...v0.9.19
[0.9.18]: https://github.com/sadjo/socialize/compare/v0.9.17...v0.9.18
[0.9.17]: https://github.com/sadjo/socialize/compare/v0.9.16...v0.9.17
[0.9.16]: https://github.com/sadjo/socialize/compare/v0.9.15...v0.9.16
[0.9.15]: https://github.com/sadjo/socialize/compare/v0.9.14...v0.9.15
[0.9.14]: https://github.com/sadjo/socialize/compare/v0.9.13...v0.9.14
[0.9.13]: https://github.com/sadjo/socialize/compare/v0.9.12...v0.9.13
[0.9.12]: https://github.com/sadjo/socialize/compare/v0.9.11...v0.9.12
[0.9.11]: https://github.com/sadjo/socialize/compare/v0.9.10...v0.9.11
[0.9.10]: https://github.com/sadjo/socialize/compare/v0.9.9...v0.9.10
[0.9.9]: https://github.com/sadjo/socialize/compare/v0.9.8...v0.9.9
[0.9.8]: https://github.com/sadjo/socialize/compare/v0.9.7...v0.9.8
[0.9.7]: https://github.com/sadjo/socialize/compare/v0.9.6...v0.9.7
[0.9.6]: https://github.com/sadjo/socialize/compare/v0.9.5...v0.9.6
[0.9.5]: https://github.com/sadjo/socialize/compare/v0.9.4...v0.9.5
[0.9.4]: https://github.com/sadjo/socialize/compare/v0.9.3...v0.9.4
[0.9.3]: https://github.com/sadjo/socialize/compare/v0.9.2...v0.9.3
[0.9.2]: https://github.com/sadjo/socialize/compare/v0.9.1...v0.9.2
[0.9.1]: https://github.com/sadjo/socialize/compare/v0.9.0...v0.9.1
[0.9.0]: https://github.com/sadjo/socialize/compare/v0.8.7...v0.9.0
[0.8.7]: https://github.com/sadjo/socialize/compare/v0.8.6...v0.8.7
[0.8.6]: https://github.com/sadjo/socialize/compare/v0.8.5...v0.8.6
[0.8.5]: https://github.com/sadjo/socialize/compare/v0.8.4...v0.8.5
[0.8.4]: https://github.com/sadjo/socialize/compare/v0.8.3...v0.8.4
[0.8.3]: https://github.com/sadjo/socialize/compare/v0.8.2...v0.8.3
[0.8.2]: https://github.com/sadjo/socialize/compare/v0.8.1...v0.8.2
[0.8.1]: https://github.com/sadjo/socialize/compare/v0.8.0...v0.8.1
[0.8.0]: https://github.com/sadjo/socialize/compare/v0.7.12...v0.8.0
[0.7.12]: https://github.com/sadjo/socialize/compare/v0.7.11...v0.7.12
[0.7.11]: https://github.com/sadjo/socialize/compare/v0.7.10...v0.7.11
[0.7.10]: https://github.com/sadjo/socialize/compare/v0.7.9...v0.7.10
[0.7.9]: https://github.com/sadjo/socialize/compare/v0.7.8...v0.7.9
[0.7.8]: https://github.com/sadjo/socialize/compare/v0.7.7...v0.7.8
[0.7.7]: https://github.com/sadjo/socialize/compare/v0.7.6...v0.7.7
[0.7.6]: https://github.com/sadjo/socialize/compare/v0.7.5...v0.7.6
[0.7.5]: https://github.com/sadjo/socialize/compare/v0.7.4...v0.7.5
[0.7.4]: https://github.com/sadjo/socialize/compare/v0.7.3...v0.7.4
[0.7.3]: https://github.com/sadjo/socialize/compare/v0.7.2...v0.7.3
[0.7.2]: https://github.com/sadjo/socialize/compare/v0.7.1...v0.7.2
[0.7.1]: https://github.com/sadjo/socialize/compare/v0.7.0...v0.7.1
[0.7.0]: https://github.com/sadjo/socialize/compare/v0.6.8...v0.7.0
[0.6.8]: https://github.com/sadjo/socialize/compare/v0.6.7...v0.6.8
[0.6.7]: https://github.com/sadjo/socialize/compare/v0.6.6...v0.6.7
[0.6.6]: https://github.com/sadjo/socialize/compare/v0.6.5...v0.6.6
[0.6.5]: https://github.com/sadjo/socialize/compare/v0.6.4...v0.6.5
[0.6.4]: https://github.com/sadjo/socialize/compare/v0.6.3...v0.6.4
[0.6.3]: https://github.com/sadjo/socialize/compare/v0.6.2...v0.6.3
[0.6.2]: https://github.com/sadjo/socialize/compare/v0.6.1...v0.6.2
[0.6.1]: https://github.com/sadjo/socialize/compare/v0.6.0...v0.6.1
[0.6.0]: https://github.com/sadjo/socialize/compare/v0.5.0...v0.6.0
[0.5.0]: https://github.com/sadjo/socialize/compare/v0.4.1...v0.5.0
[0.4.1]: https://github.com/sadjo/socialize/compare/v0.4.0...v0.4.1
[0.4.0]: https://github.com/sadjo/socialize/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/sadjo/socialize/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/sadjo/socialize/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/sadjo/socialize/releases/tag/v0.1.0
