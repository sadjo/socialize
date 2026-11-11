<?php

declare(strict_types=1);

namespace App\Federation\Application;

use App\Core\Application\Post\PostAccess;
use App\Core\Domain\Account\Account;
use App\Core\Domain\Media\MediaAttachment;
use App\Core\Domain\Music\MusicTrack;
use App\Core\Domain\Post\Post;
use App\Core\Domain\Post\Visibility;
use App\Core\Domain\Social\Comment;
use App\Media\Application\MediaUrls;

/**
 * Représentations ActivityPub (JSON-LD) des comptes, publications et commentaires locaux,
 * au format attendu par Mastodon et Pixelfed.
 */
final readonly class Serializer
{
    public const array CONTEXT = [
        'https://www.w3.org/ns/activitystreams',
        'https://w3id.org/security/v1',
        [
            'manuallyApprovesFollowers' => 'as:manuallyApprovesFollowers',
            'sensitive' => 'as:sensitive',
            'Hashtag' => 'as:Hashtag',
            'toot' => 'http://joinmastodon.org/ns#',
            'blurhash' => 'toot:blurhash',
            'discoverable' => 'toot:discoverable',
        ],
    ];

    public function __construct(
        private ApUrls $urls,
        private Signer $signer,
        private MediaUrls $media,
        private PostAccess $access,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function actor(Account $account): array
    {
        $actor = [
            '@context' => self::CONTEXT,
            'id' => $this->urls->actor($account),
            'type' => 'Person',
            'preferredUsername' => $account->getUsername(),
            'name' => $account->getDisplayName(),
            'summary' => null !== $account->getBio() ? $this->html($account->getBio()) : '',
            'url' => $this->urls->profile($account),
            'inbox' => $this->urls->inbox($account),
            'outbox' => $this->urls->outbox($account),
            'followers' => $this->urls->followers($account),
            'following' => $this->urls->following($account),
            'endpoints' => ['sharedInbox' => $this->urls->sharedInbox()],
            'manuallyApprovesFollowers' => false,
            'discoverable' => true,
            'published' => $account->getCreatedAt()->format(\DATE_ATOM),
            'publicKey' => [
                'id' => $this->urls->key($account),
                'owner' => $this->urls->actor($account),
                'publicKeyPem' => $this->signer->publicKeyOf($account),
            ],
        ];
        if (null !== $account->getAvatarPath()) {
            $actor['icon'] = ['type' => 'Image', 'mediaType' => 'image/webp', 'url' => $this->urls->base().$this->media->path($account->getAvatarPath())];
        }

        return $actor;
    }

    /**
     * Acteur de l'instance (type Application), qui signe les lectures faites au nom du serveur.
     *
     * @return array<string, mixed>
     */
    public function instanceActor(): array
    {
        $id = $this->urls->instanceActor();

        return [
            '@context' => self::CONTEXT,
            'id' => $id,
            'type' => 'Application',
            'preferredUsername' => $this->urls->domain(),
            'name' => 'Socialize',
            'inbox' => $id.'/inbox',
            'outbox' => $id.'/outbox',
            'manuallyApprovesFollowers' => true,
            'publicKey' => ['id' => $id.'#main-key', 'owner' => $id, 'publicKeyPem' => $this->signer->instance()['publicKey']],
        ];
    }

    /**
     * Objet Note d'une publication locale (sans @context, à ajouter si l'objet est servi seul).
     *
     * @return array<string, mixed>
     */
    public function note(Post $post): array
    {
        $author = $post->getAccount();
        [$to, $cc] = $this->addressing($post->getVisibility(), $author, $this->access->groupAudience($post));

        $attachments = [];
        foreach ($post->getMedia() as $media) {
            if ($media->isReady()) {
                $attachments[] = $this->attachment($media);
            }
        }

        $content = $this->html($post->getCaption(), $post->isImported()).$this->musicCredit($post);
        $note = [
            'id' => $this->urls->note($post),
            'type' => 'Note',
            'attributedTo' => $this->urls->actor($author),
            'published' => $post->getPublishedAt()->format(\DATE_ATOM),
            'url' => $this->urls->note($post),
            'to' => $to,
            'cc' => $cc,
            'content' => $content,
            'contentMap' => ['fr' => $content],
            'sensitive' => $post->isSensitive(),
            'summary' => $post->getContentWarning(),
            'attachment' => $attachments,
            'tag' => $this->tags($post->getCaption()),
        ];
        if ($post->getUpdatedAt() instanceof \DateTimeImmutable) {
            $note['updated'] = $post->getUpdatedAt()->format(\DATE_ATOM);
        }
        if (null !== $post->getLocation()) {
            $note['location'] = ['type' => 'Place', 'name' => $post->getLocation()];
        }

        return $note;
    }

    /**
     * Objet Note d'un commentaire local (réponse à une publication).
     *
     * @return array<string, mixed>
     */
    public function commentNote(Comment $comment): array
    {
        $post = $comment->getPost();
        $author = $comment->getAccount();
        $postAuthor = $post->getAccount();
        [$to, $cc] = $this->addressing($post->getVisibility(), $postAuthor, $this->access->groupAudience($post));

        return [
            'id' => $this->urls->comment($comment),
            'type' => 'Note',
            'attributedTo' => $this->urls->actor($author),
            'inReplyTo' => $this->urls->note($post),
            'published' => $comment->getCreatedAt()->format(\DATE_ATOM),
            'url' => $this->urls->comment($comment),
            // Même audience que la publication commentée, et son auteur en copie.
            'to' => $to,
            'cc' => array_values(array_unique([...$cc, $this->urls->actor($postAuthor)])),
            'content' => $this->html($comment->getContent()),
            'tag' => array_merge($this->tags($comment->getContent()), $postAuthor->isLocal() ? [] : [[
                'type' => 'Mention',
                'href' => $this->urls->actor($postAuthor),
                'name' => '@'.$postAuthor->getAcct(),
            ]]),
        ];
    }

    /**
     * Destinataires (to, cc) selon la visibilité. Les publications privées ne sont pas fédérées.
     *
     * @param list<Account> $audience membres abonnés des groupes (visibilité « Groupes »)
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    public function addressing(Visibility $visibility, Account $author, array $audience = []): array
    {
        $followers = $this->urls->followers($author);

        return match ($visibility) {
            Visibility::Public => [[ApUrls::PUBLIC], [$followers]],
            Visibility::Unlisted => [[$followers], [ApUrls::PUBLIC]],
            Visibility::Followers => [[$followers], []],
            // Réservée à des groupes : adressée nommément à leurs membres abonnés (Mastodon la montre à eux seuls).
            Visibility::Groups => [array_values(array_unique(array_map($this->urls->actor(...), $audience))), []],
            Visibility::Private => [[], []],
        };
    }

    /**
     * @param array<string, mixed> $object
     *
     * @return array<string, mixed>
     */
    public function withContext(array $object): array
    {
        return ['@context' => self::CONTEXT] + $object;
    }

    /**
     * @return array<string, mixed>
     */
    private function attachment(MediaAttachment $media): array
    {
        $name = $media->isVideo() ? MediaAttachment::DERIVATIVE_VIDEO : MediaAttachment::DERIVATIVE_LARGE;
        $url = $this->absolute((string) $this->media->derivative($media, $name));

        // Vignette d'une vidéo : sans elle, Mastodon affiche une case vide dans une galerie mêlant photos et vidéos.
        $poster = $media->isVideo() ? $this->media->derivative($media, MediaAttachment::DERIVATIVE_POSTER) : null;
        $icon = null !== $poster ? ['type' => 'Image', 'mediaType' => 'image/webp', 'url' => $this->absolute($poster)] : null;

        return array_filter([
            'type' => $media->isVideo() ? 'Video' : 'Image',
            'mediaType' => $media->isVideo() ? 'video/mp4' : 'image/webp',
            'url' => $url,
            'icon' => $icon,
            'name' => $media->getAltText(),
            'blurhash' => $media->getBlurhash(),
            'width' => $media->getWidth(),
            'height' => $media->getHeight(),
        ], static fn (mixed $v): bool => null !== $v);
    }

    private function absolute(string $url): string
    {
        return str_starts_with($url, 'http') ? $url : $this->urls->base().$url;
    }

    /**
     * @return list<array<string, string>>
     */
    private function tags(string $text): array
    {
        return array_map(fn (string $tag): array => ['type' => 'Hashtag', 'href' => $this->urls->hashtag($tag), 'name' => '#'.$tag], HtmlText::hashtags($text));
    }

    /**
     * Mention de la musique, que les autres serveurs du fédivers ne jouent pas : « 🎵 Titre – Artiste (licence) ».
     */
    private function musicCredit(Post $post): string
    {
        $music = $post->getMusic();
        if (!$music instanceof MusicTrack) {
            return '';
        }
        $label = htmlspecialchars(\sprintf('%s – %s', $music->getTitle(), $music->getArtist()), \ENT_QUOTES | \ENT_HTML5);
        if (null !== $music->getSourceUrl()) {
            $label = \sprintf('<a href="%s" rel="nofollow noopener" target="_blank">%s</a>', htmlspecialchars($music->getSourceUrl(), \ENT_QUOTES | \ENT_HTML5), $label);
        }

        return \sprintf('<p>🎵 %s (%s)</p>', $label, htmlspecialchars($music->getLicenseLabel(), \ENT_QUOTES | \ENT_HTML5));
    }

    /**
     * @param bool $instagram texte importé d'Instagram : les mentions mènent aux profils Instagram
     */
    private function html(string $text, bool $instagram = false): string
    {
        return HtmlText::toHtml(
            $text,
            fn (string $tag): string => $this->urls->hashtag($tag),
            fn (string $handle): string => $instagram && !str_contains($handle, '@')
                ? 'https://www.instagram.com/'.rawurlencode(rtrim($handle, '.')).'/'
                : $this->urls->base().'/@'.$handle,
        );
    }
}
