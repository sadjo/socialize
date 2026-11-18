<?php

declare(strict_types=1);

namespace App\Core\Application\Post;

use App\Core\Domain\Account\Account;
use App\Core\Infrastructure\Doctrine\AccountRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Mentions d'un texte (légende) :
 * - « @camille » : compte de cette instance ;
 * - « @camille@pixelfed.social » : compte du fédivers (ou de cette instance, si c'est son domaine) ;
 * - « ig:@camille » : compte Instagram, qui mène à son profil Instagram.
 */
final readonly class Mentions
{
    /**
     * Toutes les formes : (1) « ig: » éventuel, (2) nom, (3) domaine éventuel.
     */
    public const string PATTERN = '/(?<![\w\/])(?:(ig:)|(?<!ig:))@([a-z0-9_.]{1,30})(?:@([a-z0-9-]+(?:\.[a-z0-9-]+)*\.[a-z]{2,}))?/iu';

    private const int MAX = 20;

    public function __construct(
        private AccountRepository $accounts,
        #[Autowire(param: 'app.local_domain')]
        private string $localDomain,
    ) {
    }

    /**
     * Comptes mentionnés (hors Instagram), sous la forme nom et domaine (null : cette instance), sans doublon.
     *
     * @return list<array{username: string, domain: ?string}>
     */
    public function handles(string $text): array
    {
        $handles = [];
        foreach ($this->matches($text) as [$instagram, $username, $domain]) {
            if ($instagram) {
                continue;
            }
            $username = mb_strtolower(rtrim($username, '.'));
            $domain = null !== $domain ? mb_strtolower($domain) : null;
            if ($domain === mb_strtolower($this->localDomain)) {
                $domain = null;
            }
            $handles[$username.'@'.$domain] = ['username' => $username, 'domain' => $domain];
        }

        return \array_slice(array_values($handles), 0, self::MAX);
    }

    /**
     * Comptes mentionnés déjà connus de l'instance (locaux, ou du fédivers déjà rencontrés).
     *
     * @return list<Account>
     */
    public function accounts(string $text): array
    {
        $found = [];
        foreach ($this->handles($text) as $handle) {
            $account = null === $handle['domain']
                ? $this->accounts->findLocal($handle['username'])
                : $this->accounts->findOneBy(['username' => $handle['username'], 'domain' => $handle['domain']]);
            if ($account instanceof Account) {
                $found[] = $account;
            }
        }

        return $found;
    }

    /**
     * Profil Instagram d'un nom de compte.
     */
    public static function instagramUrl(string $username): string
    {
        return 'https://www.instagram.com/'.rawurlencode(rtrim($username, '.')).'/';
    }

    /**
     * @return list<array{0: bool, 1: string, 2: ?string}> Instagram ?, nom, domaine
     */
    private function matches(string $text): array
    {
        preg_match_all(self::PATTERN, Links::without($text), $all, \PREG_SET_ORDER | \PREG_UNMATCHED_AS_NULL);

        return array_map(static fn (array $m): array => [null !== $m[1], (string) $m[2], $m[3]], $all);
    }
}
