<?php

declare(strict_types=1);

namespace App\Web\Twig;

use App\Core\Application\Post\Links;
use App\Core\Application\Post\Mentions;
use App\Core\Domain\Account\Account;
use App\Core\Domain\Media\MediaAttachment;
use App\Media\Application\ImageSizes;
use App\Media\Application\MediaUrls;
use Twig\Attribute\AsTwigFilter;
use Twig\Attribute\AsTwigFunction;

final readonly class SocializeExtension
{
    /** Pastille devant une mention : compte d'ici, du fédivers (Mastodon), d'Instagram. */
    private const array MENTION_ICONS = [
        'local' => '<span class="mi mi-local" aria-hidden="true"></span>',
        'fedi' => '<span class="mi mi-fedi" aria-hidden="true"></span>',
        'ig' => '<span class="mi mi-ig" aria-hidden="true"></span>',
    ];

    public function __construct(
        private MediaUrls $urls,
    ) {
    }

    #[AsTwigFunction('media_url')]
    public function mediaUrl(MediaAttachment $media, string $derivative = MediaAttachment::DERIVATIVE_LARGE): ?string
    {
        return $this->urls->derivative($media, $derivative);
    }

    /**
     * Tailles proposées au navigateur (srcset) : « large » pour l'image entière, « thumb » pour la vignette de grille.
     */
    #[AsTwigFunction('media_srcset')]
    public function mediaSrcset(MediaAttachment $media, string $family = ImageSizes::LARGE): ?string
    {
        return $this->urls->srcset($media, $family);
    }

    #[AsTwigFunction('avatar_url')]
    public function avatarUrl(Account $account): ?string
    {
        $path = $account->getAvatarPath();
        if (null !== $path) {
            return $this->urls->path($path);
        }

        return $account->isLocal() ? null : $account->getAvatarUrl();
    }

    /**
     * Image de couverture : fichier déposé ici d'abord, sinon celle de l'instance d'un compte distant.
     */
    #[AsTwigFunction('cover_url')]
    public function coverUrl(Account $account): ?string
    {
        $path = $account->getCoverPath();
        if (null !== $path) {
            return $this->urls->path($path);
        }

        return $account->isLocal() ? null : $account->getCoverUrl();
    }

    /**
     * Image source gardée pour recadrer la photo (« avatar ») ou la couverture (« cover ») ; null sans source.
     */
    #[AsTwigFunction('profile_image_source')]
    public function profileImageSource(Account $account, string $kind): ?string
    {
        $path = 'avatar' === $kind ? $account->getAvatarSourcePath() : $account->getCoverSourcePath();

        return null !== $path ? $this->urls->path($path) : null;
    }

    #[AsTwigFunction('handle')]
    public function handle(Account $account, string $localDomain): string
    {
        return '@'.$account->getUsername().'@'.($account->getDomain() ?? $localDomain);
    }

    /**
     * Durée relative courte, en français : « à l’instant », « il y a 5 min », « 3 j »….
     */
    #[AsTwigFilter('ago')]
    public function ago(\DateTimeInterface $date, ?\DateTimeInterface $now = null): string
    {
        $seconds = ($now ?? new \DateTimeImmutable())->getTimestamp() - $date->getTimestamp();

        return match (true) {
            $seconds < 60 => 'à l’instant',
            $seconds < 3600 => \sprintf('il y a %d min', intdiv($seconds, 60)),
            $seconds < 86400 => \sprintf('il y a %d h', intdiv($seconds, 3600)),
            $seconds < 7 * 86400 => \sprintf('il y a %d j', intdiv($seconds, 86400)),
            default => $this->frenchDate($date),
        };
    }

    /**
     * Texte saisi par un compte : échappé, retours à la ligne conservés,
     * adresses web en liens, hashtags et mentions mis en valeur.
     *
     * Texte importé d'Instagram : les mentions (« @compte ») désignent des comptes Instagram,
     * et mènent donc à leur profil sur Instagram plutôt qu'à un profil de l'instance.
     */
    #[AsTwigFilter('rich_text', isSafe: ['html'])]
    public function richText(string $text, bool $instagram = false): string
    {
        $html = '';
        foreach (Links::split($text) as [$isLink, $part]) {
            $html .= $isLink ? $this->link($part) : $this->inlineText($part, $instagram);
        }

        return nl2br($html, false);
    }

    /**
     * Adresse web : lien externe, affiché sans « https:// » et raccourci s'il est long.
     */
    private function link(string $url): string
    {
        [, $visible, $rest] = Links::display($url);
        $e = static fn (string $s): string => htmlspecialchars($s, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');

        return \sprintf('<a class="link" href="%s" target="_blank" rel="noopener nofollow ugc" title="%s">%s%s</a>', $e(Links::href($url)), $e(Links::href($url)), $e($visible), '' !== $rest ? '…' : '');
    }

    /**
     * Texte ordinaire (hors adresse web) : échappé, hashtags et mentions mis en valeur.
     */
    private function inlineText(string $text, bool $instagram): string
    {
        $html = htmlspecialchars($text, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
        $html = (string) preg_replace('/(?<![\w&])#([\p{L}\p{N}_]{1,100})/u', '<span class="tag">#$1</span>', $html);

        return (string) preg_replace_callback(
            Mentions::PATTERN,
            static fn (array $m): string => match (true) {
                // « ig:@nom » : compte Instagram.
                null !== $m[1] => \sprintf('<a class="tag ig" href="%s" target="_blank" rel="noopener nofollow" title="Compte Instagram">%s@%s</a>', htmlspecialchars(Mentions::instagramUrl($m[2])), self::MENTION_ICONS['ig'], $m[2]).(null !== $m[3] ? '@'.$m[3] : ''),
                // Texte importé d'Instagram : « @nom » y désigne un compte Instagram.
                $instagram && null === $m[3] => \sprintf('<a class="tag" href="%s" target="_blank" rel="noopener nofollow">%s@%s</a>', self::instagramProfile($m[2]), self::MENTION_ICONS['ig'], $m[2]),
                // Compte du fédivers (icône Mastodon) ou d'ici (icône Socialize).
                default => \sprintf('<a class="tag" href="/@%s%s">%s@%s%s</a>', $m[2], null !== $m[3] ? '@'.$m[3] : '', self::MENTION_ICONS[null !== $m[3] ? 'fedi' : 'local'], $m[2], null !== $m[3] ? '@'.$m[3] : ''),
            },
            $html,
            flags: \PREG_UNMATCHED_AS_NULL,
        );
    }

    /**
     * Profil Instagram d'un compte mentionné dans un contenu importé.
     */
    public static function instagramProfile(string $username): string
    {
        return 'https://www.instagram.com/'.rawurlencode(rtrim($username, '.')).'/';
    }

    private function frenchDate(\DateTimeInterface $date): string
    {
        $months = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
        $sameYear = $date->format('Y') === date('Y');

        return \sprintf('%d %s%s', (int) $date->format('j'), $months[(int) $date->format('n') - 1], $sameYear ? '' : ' '.$date->format('Y'));
    }
}
