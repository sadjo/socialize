# User Guide

> The interface of Socialize is currently in French. This guide gives the French labels in *italics*.

## Your account

- **Sign in** with your e-mail address or your username (`camille` or `@camille`). Accounts that only exist on
  Mastodon can use *Se connecter avec Mastodon* when the instance allows it (see [Mastodon Sign-In](Mastodon-Sign-In)).
- **Profile** (*Réglages*): display name, biography, profile picture and cover image (4:1 banner). Both images are
  cropped in the browser — drag and zoom — and can be repositioned later.
- **Privacy**: *Réserver mes listes à mes abonnés* hides your followers and following lists from other visitors.
  *Valider moi-même les demandes d'abonnement* makes every new follower, local or from the fediverse, wait for your
  approval; requests show up in your notifications and are handled on the *Demandes* page of your profile (Accepter,
  Refuser). Unticking the setting accepts the pending requests.
- **Appearance** (*Réglages › Apparence*): light, dark, night violet or automatic theme, previewed instantly, and a
  **media filter** (off, light, medium, strong) that softens the colors of photos and videos in feeds and grids —
  post pages, the viewer and stories keep the original colors.
- **Home screen app**: on iPhone/iPad use *Share › Add to Home Screen*; on Android, *Install app*. Socialize then
  opens full screen and can send push notifications.

Your address in the fediverse is `@username@your-instance`: share it with people on Mastodon so they can follow you.

## Posting

Use the **Create** button (*Créer*) and choose *Publication* or *Story*. Only accounts with the **Publish**
permission can post (administrators always can).

### Posts

- Up to **20 photos and videos** per post, from your device or from a connected photo library
  ([Immich, Synology Photos](Integrations)). Selections add up; reorder by drag and drop or with ◀ ▶, open the
  magnifier for a large preview, remove a media from the preview.
- **Alt text** for every media — please describe your images for people using screen readers.
- **Caption** with `#hashtags` and mentions (see below), **location**, and an optional **content warning** that
  blurs the media until the viewer chooses to see it.
- **Music** (*Ajouter une musique*): search Creative Commons tracks from Openverse, preview them, pick the start of
  the 30-second loop. Credits are displayed automatically. Your favorite tracks are suggested first.
- **Visibility**:

| Visibility | Who can see it | Sent to the fediverse |
| --- | --- | --- |
| *Public* | Everyone, listed in public feeds | Yes |
| *Non répertorié* (unlisted) | Anyone with the link, not listed in feeds | No |
| *Abonnés* (followers) | Your accepted followers | Yes |
| *Groupes* (groups) | Followers who belong to the selected groups | No |
| *Privé* (private) | Only you | No |

Photos are converted to WebP and videos to MP4 in the background; a post containing videos stays pending
(*en attente*) until the conversion is done, with its progress shown on your profile.

After publishing you can **edit** the caption, location, visibility, content warning and media, **archive** the post
(it disappears from your profile and feeds but stays visible to you under *Archives*) or **delete** it.

### Stories

- One photo or video per story, up to 10 at once, with an optional text overlay and music.
- Visible for **24 hours** to everyone or to your followers (or to groups).
- Expired stories go to your archive. From *Mes stories* you can pin them as **highlights** on your profile.
- You can see who viewed your stories.

## Audience groups

*Réglages › Groupes*: create groups (family, close friends…) and pick their members among your followers. When
posting, choose the *Groupes* visibility and one or more groups. Members see a *Groupe* badge; you see the group
names. Deleting a group makes the posts that only targeted it private.

## Feed, likes and comments

- **Home** (*Publications*): posts of the accounts you follow and your own. The story bar shows your stories first,
  then unseen stories of the accounts you follow.
- **Like** with the heart or a double tap; click the counter to see who liked a post.
- **Comment** directly in the feed; the author of a comment or of the post can delete it.
- The **⋯ menu** of a post: open, share (e-mail, copy link, Facebook, Instagram, system share sheet), see on the
  original instance, edit, archive, delete.
- Videos play muted when visible; the sound button applies to all videos. Click a photo or video to open the
  full-screen viewer and browse the carousel with the arrows, the keyboard or by swiping.

## Mentions

In captions and comments:

| Syntax | Mentions |
| --- | --- |
| `@name` | an account of this instance |
| `@name@instance` | an account of the fediverse (Mastodon, Pixelfed…) |
| `ig:@name` | an Instagram account |

Web addresses (`https://…`, `www.example.org`, `example.org/page`) become links, here and in the fediverse.

Suggestions appear as soon as you type `@`: accounts you follow, local accounts, then fediverse accounts. A second
`@` switches to fediverse accounts only, and an unknown full address is looked up on its server. `Esc` closes the
suggestions, `Ctrl+Space` opens them again. Mentioned accounts are notified.

## Following

Search accounts (*Recherche*) by name or by fediverse address (`@name@instance`). Following a remote account
imports its recent photo and video posts. Remote accounts that require approval show *Demande envoyée* until they
accept. On your own profile you can remove a follower or unfollow an account.

## Notifications

*Notifications* lists likes, comments and mentions received from here and from the fediverse; mark them read or
unread, or delete them. Administrators are also notified of new accounts.

*Notifications › Sur cet appareil* enables **push notifications** on the current device: new posts and stories of
the accounts you follow, likes and comments, each type can be switched on or off.

## Importing your Instagram archive

If your account has the **Instagram import** permission, see [Integrations › Instagram import](Integrations#instagram-import).
