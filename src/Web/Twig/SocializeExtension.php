<?php

declare(strict_types=1);

namespace App\Web\Twig;

use App\Core\Domain\Account\Account;
use App\Core\Domain\Media\MediaAttachment;
use App\Media\Application\MediaUrls;
use Twig\Attribute\AsTwigFilter;
use Twig\Attribute\AsTwigFunction;

final readonly class SocializeExtension
{
    public function __construct(
        private MediaUrls $urls,
    ) {
    }

    #[AsTwigFunction('media_url')]
    public function mediaUrl(MediaAttachment $media, string $derivative = MediaAttachment::DERIVATIVE_LARGE): ?string
    {
        return $this->urls->derivative($media, $derivative);
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
     * hashtags et mentions mis en valeur.
     *
     * Texte importé d'Instagram : les mentions (« @compte ») désignent des comptes Instagram,
     * et mènent donc à leur profil sur Instagram plutôt qu'à un profil de l'instance.
     */
    #[AsTwigFilter('rich_text', isSafe: ['html'])]
    public function richText(string $text, bool $instagram = false): string
    {
        $html = htmlspecialchars($text, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
        $html = (string) preg_replace('/(?<![\w&])#([\p{L}\p{N}_]{1,100})/u', '<span class="tag">#$1</span>', $html);
        $html = (string) preg_replace_callback(
            '/(?<![\w\/])@([a-z0-9_.]{1,30})(@[a-z0-9.-]+\.[a-z]{2,})?/iu',
            static fn (array $m): string => $instagram && '' === ($m[2] ?? '')
                ? \sprintf('<a class="tag" href="%s" target="_blank" rel="noopener nofollow">@%s</a>', self::instagramProfile($m[1]), $m[1])
                : \sprintf('<a class="tag" href="/@%s">@%s%s</a>', $m[1], $m[1], $m[2] ?? ''),
            $html,
        );

        return nl2br($html, false);
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
