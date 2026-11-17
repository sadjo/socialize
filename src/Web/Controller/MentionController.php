<?php

declare(strict_types=1);

namespace App\Web\Controller;

use App\Core\Domain\Account\Account;
use App\Core\Infrastructure\Doctrine\AccountRepository;
use App\Core\Infrastructure\Doctrine\FollowRepository;
use App\Federation\Application\RemoteAccounts;
use App\Web\Security\CurrentAccount;
use App\Web\Twig\SocializeExtension;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Suggestions de comptes à mentionner pendant la saisie d'une légende : comptes d'ici, puis du fédivers déjà connus.
 */
#[IsGranted('ROLE_USER')]
final class MentionController extends AbstractController
{
    private const int LIMIT = 8;

    #[Route('/mentions/suggestions', name: 'app_mention_suggestions', methods: ['GET'])]
    public function suggestions(Request $request, AccountRepository $accounts, FollowRepository $follows, CurrentAccount $current, RemoteAccounts $remote, SocializeExtension $twig, #[Autowire(param: 'app.local_domain')] string $localDomain): JsonResponse
    {
        // « cam », « cam@ » ou « cam@pixel » : le nom, et le début du domaine éventuel.
        $query = ltrim(mb_strtolower(trim($request->query->getString('q'))), '@');
        // Juste « @ » : les comptes suivis, puis d'autres comptes d'ici.
        if ('' === $query) {
            $me = $current->require();
            $found = [];
            foreach ([...$follows->followingPage($me, null, self::LIMIT)->accounts, ...$accounts->suggestionsFor($me, self::LIMIT)] as $account) {
                $found[$account->getId()->toRfc4122()] ??= $account;
            }

            return $this->json($this->describe(\array_slice(array_values($found), 0, self::LIMIT), $request->query->getBoolean('full'), $twig, $localDomain));
        }
        $parts = explode('@', $query, 2);
        $username = $parts[0];
        // Second « @ » tapé : saisie d'un compte du fédivers (Mastodon, Pixelfed…), même sans domaine encore.
        $fediverse = \array_key_exists(1, $parts);
        $domain = $parts[1] ?? '';
        if (!preg_match('/^[a-z0-9_.]{1,30}$/', $username) || !preg_match('/^[a-z0-9.-]{0,253}$/', $domain)) {
            return $this->json([]);
        }

        $found = array_values(array_filter(
            $accounts->search($username, 30),
            static fn (Account $a): bool => $fediverse
                ? ('' === $domain ? !$a->isLocal() : str_starts_with($a->getDomain() ?? $localDomain, $domain))
                : true,
        ));
        // Adresse complète d'un compte encore inconnu : cherchée sur son serveur (WebFinger).
        if ($fediverse && preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)*\.[a-z]{2,}$/', $domain) && $domain !== $localDomain
            && !array_any($found, static fn (Account $a): bool => $a->getUsername() === $username && $a->getDomain() === $domain)) {
            try {
                // Seulement le compte de ce nom (son serveur peut répondre avec un autre domaine, s'il délègue).
                $account = $remote->byHandle($username, $domain);
                if ($account->getUsername() === $username) {
                    array_unshift($found, $account);
                }
            } catch (\Throwable) {
                // Adresse introuvable (pour l'instant) : rien de plus à proposer.
            }
        }
        // Comptes d'ici d'abord, puis le nom exact, puis ceux dont le nom commence par la saisie.
        usort($found, static fn (Account $a, Account $b): int => [!$a->isLocal(), $a->getUsername() !== $username, !str_starts_with($a->getUsername(), $username), $a->getUsername()]
            <=> [!$b->isLocal(), $b->getUsername() !== $username, !str_starts_with($b->getUsername(), $username), $b->getUsername()]);

        return $this->json($this->describe(\array_slice($found, 0, self::LIMIT), $request->query->getBoolean('full'), $twig, $localDomain));
    }

    /**
     * @param list<Account> $accounts
     *
     * @return list<array<string, mixed>>
     */
    private function describe(array $accounts, bool $full, SocializeExtension $twig, string $localDomain): array
    {
        // Texte envoyé à un serveur Mastodon ($full) : adresse complète, sinon « @nom » y désignerait un compte de ce
        // serveur.
        return array_map(static fn (Account $a): array => [
            'mention' => $full ? '@'.$a->getUsername().'@'.($a->getDomain() ?? $localDomain) : '@'.$a->getAcct(),
            'name' => $a->getDisplayName(),
            'handle' => '@'.$a->getUsername().'@'.($a->getDomain() ?? $localDomain),
            'avatar' => $twig->avatarUrl($a),
            'initials' => $a->getInitials(),
            'hue' => $a->getHue(),
        ], $accounts);
    }
}
