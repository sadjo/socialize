<?php

declare(strict_types=1);

namespace App\Web\Security;

use App\Core\Domain\Account\User;
use App\Core\Infrastructure\Doctrine\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Uuid;

/**
 * « Mot de passe oublié » des comptes de l'instance, sans rien enregistrer : le lien envoyé par e-mail est signé, expire
 * au bout d'une heure, et porte l'empreinte du mot de passe actuel. Il ne sert donc qu'une fois : dès que le mot de passe
 * change, l'empreinte ne correspond plus.
 */
final readonly class PasswordReset
{
    public const string LIFETIME = 'PT1H';

    public function __construct(
        private UriSigner $signer,
        private UrlGeneratorInterface $urls,
        private UserRepository $users,
        private UserPasswordHasherInterface $hasher,
        private EntityManagerInterface $em,
        #[Autowire(param: 'kernel.secret')]
        private string $secret,
    ) {
    }

    /**
     * Lien de réinitialisation, valable une heure.
     */
    public function linkFor(User $user): string
    {
        $url = $this->urls->generate('app_reset_password', [
            'compte' => $user->getId()->toRfc4122(),
            'empreinte' => $this->fingerprint($user),
        ], UrlGeneratorInterface::ABSOLUTE_URL);

        return $this->signer->sign($url, new \DateInterval(self::LIFETIME));
    }

    /**
     * Compte du lien ouvert, ou null si le lien est altéré, expiré ou déjà utilisé.
     */
    public function userFor(Request $request): ?User
    {
        if (!$this->signer->checkRequest($request)) {
            return null;
        }
        $id = $request->query->getString('compte');
        if (!Uuid::isValid($id)) {
            return null;
        }
        $user = $this->users->find(Uuid::fromString($id));
        if (!$user instanceof User || !hash_equals($this->fingerprint($user), $request->query->getString('empreinte'))) {
            return null;
        }

        return $user;
    }

    public function reset(User $user, #[\SensitiveParameter] string $plainPassword): void
    {
        $user->changePassword($this->hasher->hashPassword($user, $plainPassword));
        $this->em->flush();
    }

    private function fingerprint(User $user): string
    {
        return substr(hash_hmac('sha256', 'password-reset|'.$user->getId()->toRfc4122().'|'.$user->getPassword(), $this->secret), 0, 32);
    }
}
