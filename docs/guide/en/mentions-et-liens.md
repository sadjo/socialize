# Mentions, hashtags and links

In captions, comments and the biography:

| You type | It becomes |
| --- | --- |
| `@name` | a mention of an account of this instance |
| `@name@instance` | a mention of a fediverse account (Mastodon, Pixelfed…) |
| `ig:@name` | a link to an Instagram account |
| `#word` | a hashtag |
| `https://…`, `www.example.org`, `example.org/page` | a link |

## Mentioning an account

Suggestions appear as soon as you type `@`: accounts you follow, local accounts, then fediverse accounts. A second `@`
switches to fediverse accounts only, and an unknown full address is looked up on its server. `Esc` closes the
suggestions, `Ctrl+Space` reopens them. Mentioned accounts are notified, here and in the fediverse.

## Links

Web addresses become links, on the page and in the fediverse, with or without `https://`. They are shortened for
display: `https://www.example.org/a/rather/long/page` becomes `example.org/a/rather/long/pag…`. Punctuation after an
address is not part of it.

A `#` or an `@` inside an address is neither a hashtag nor a mention.
