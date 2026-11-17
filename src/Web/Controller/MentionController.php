<?php

declare(strict_types=1);

namespace App\Web\Controller;

use App\Core\Domain\Account\Account;
use App\Core\Infrastructure\Doctrine\AccountRepository;
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
    public function suggestions(Request $request, AccountRepository $accounts, SocializeExtension $twig, #[Autowire(param: 'app.local_domain')] string $localDomain): JsonResponse
    {
        // « cam » ou « cam@pixel » : le nom, et le début du domaine éventuel.
        $query = ltrim(mb_strtolower(trim($request->query->getString('q'))), '@');
        $parts = explode('@', $query, 2);
        $username = $parts[0];
        $domain = $parts[1] ?? '';
        if (!preg_match('/^[a-z0-9_.]{1,30}$/', $username)) {
            return $this->json([]);
        }

        $found = array_values(array_filter(
            $accounts->search($username, 30),
            static fn (Account $a): bool => '' === $domain || str_starts_with($a->getDomain() ?? $localDomain, $domain),
        ));
        // Comptes d'ici d'abord, puis ceux dont le nom commence par la saisie.
        usort($found, static fn (Account $a, Account $b): int => [!$a->isLocal(), !str_starts_with($a->getUsername(), $username), $a->getUsername()]
            <=> [!$b->isLocal(), !str_starts_with($b->getUsername(), $username), $b->getUsername()]);

        // Texte envoyé à un serveur Mastodon : adresse complète, sinon « @nom » y désignerait un compte de ce serveur.
        $full = $request->query->getBoolean('full');

        return $this->json(array_map(static fn (Account $a): array => [
            'mention' => $full ? '@'.$a->getUsername().'@'.($a->getDomain() ?? $localDomain) : '@'.$a->getAcct(),
            'name' => $a->getDisplayName(),
            'handle' => '@'.$a->getUsername().'@'.($a->getDomain() ?? $localDomain),
            'avatar' => $twig->avatarUrl($a),
            'initials' => $a->getInitials(),
            'hue' => $a->getHue(),
        ], \array_slice($found, 0, self::LIMIT)));
    }
}
