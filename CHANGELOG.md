# Changelog

All notable changes to Socialize are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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

[Unreleased]: https://github.com/sadjo/socialize/compare/v0.9.19...HEAD
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
