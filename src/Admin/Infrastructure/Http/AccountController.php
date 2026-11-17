<?php

declare(strict_types=1);

namespace App\Admin\Infrastructure\Http;

use App\Core\Application\Account\AccountDeletion;
use App\Core\Application\Account\RegisterAccount;
use App\Core\Application\Account\RegistrationException;
use App\Core\Domain\Account\Account;
use App\Core\Domain\Account\AccountRight;
use App\Core\Domain\Account\User;
use App\Core\Domain\Event\ProfileUpdated;
use App\Core\Infrastructure\Doctrine\AccountRepository;
use App\Core\Infrastructure\Doctrine\UserRepository;
use App\Integration\Mastodon\Application\MastodonLogin;
use App\Integration\Mastodon\Domain\MastodonUser;
use App\Integration\Mastodon\Infrastructure\Doctrine\MastodonUserRepository;
use App\Media\Application\MediaProcessingException;
use App\Media\Application\ProfileImage;
use App\Media\Application\ProfileImages;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Espace Administration : comptes de l'instance, et création d'un compte par l'administrateur.
 */
#[IsGranted(User::ROLE_ADMIN)]
final class AccountController extends AbstractController
{
    #[Route('/admin/comptes', name: 'admin_accounts', methods: ['GET', 'POST'])]
    public function index(Request $request, RegisterAccount $registerAccount, UserRepository $users, EntityManagerInterface $em, MastodonUserRepository $mastodonUsers): Response
    {
        $form = $this->createForm(AccountType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{email: string, username: string, displayName: ?string, password: string, publisher: bool, instagramImport: bool} $data */
            $data = $form->getData();
            try {
                $user = $registerAccount->register($data['email'], $data['username'], $data['password'], $data['displayName']);
                if ($data['publisher']) {
                    $user->grant(AccountRight::Publish);
                }
                if ($data['instagramImport']) {
                    $user->grant(AccountRight::InstagramImport);
                }
                $em->flush();
                $this->addFlash('success', \sprintf('Compte @%s créé. Transmettez son mot de passe à la personne.', $user->getAccount()->getUsername()));

                return $this->redirectToRoute('admin_accounts', status: Response::HTTP_SEE_OTHER);
            } catch (RegistrationException $e) {
                $form->addError(new FormError($e->getMessage()));
            }
        }

        return $this->render('admin/accounts.html.twig', [
            'form' => $form,
            'users' => $users->recent(),
            'rights' => AccountRight::cases(),
            'mastodon_users' => $mastodonUsers->findBy([], ['lastLoginAt' => 'DESC'], 200),
        ]);
    }

    /**
     * Nouveau mot de passe d'un compte, choisi par l'administrateur (sans longueur minimale).
     */
    #[Route('/admin/comptes/{id}/mot-de-passe', name: 'admin_account_password', requirements: ['id' => Requirement::UUID], methods: ['GET', 'POST'])]
    public function password(string $id, Request $request, UserRepository $users, UserPasswordHasherInterface $hasher, EntityManagerInterface $em, Security $security): Response
    {
        $user = $users->find($id);
        if (!$user instanceof User) {
            throw $this->createNotFoundException();
        }
        $form = $this->createForm(AccountPasswordType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{password: string} $data */
            $data = $form->getData();
            $user->changePassword($hasher->hashPassword($user, $data['password']));
            $em->flush();
            // Changer son propre mot de passe ne doit pas déconnecter l'administrateur.
            if ($user === $this->getUser()) {
                $security->login($user);
            }
            $this->addFlash('success', \sprintf('Mot de passe de @%s changé. Transmettez-le à la personne.', $user->getAccount()->getUsername()));

            return $this->redirectToRoute('admin_accounts', status: Response::HTTP_SEE_OTHER);
        }

        return $this->render('admin/account_password.html.twig', ['form' => $form, 'user' => $user]);
    }

    /**
     * Photo de profil et image de couverture d'un compte de l'instance, ou d'un compte Mastodon connecté : pour ce
     * dernier, l'image déposée ici remplace sur Socialize celle de son serveur (sans toucher à son compte Mastodon).
     */
    #[Route('/admin/comptes/{id}/images', name: 'admin_account_images', requirements: ['id' => Requirement::UUID], methods: ['GET', 'POST'])]
    public function images(string $id, Request $request, AccountRepository $accounts, UserRepository $users, MastodonUserRepository $mastodonUsers, ProfileImages $images, EntityManagerInterface $em, EventDispatcherInterface $events): Response
    {
        $account = $accounts->find($id);
        $managed = $account instanceof Account && ($account->isLocal()
            ? $users->findOneBy(['account' => $account]) instanceof User
            : $mastodonUsers->forAccount($account) instanceof MastodonUser);
        if (!$account instanceof Account || !$managed) {
            throw $this->createNotFoundException();
        }
        $remote = !$account->isLocal();
        $form = $this->createForm(AccountImagesType::class, options: ['remote' => $remote]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $field = 'avatar';
            try {
                /** @var array{avatar: ?UploadedFile, avatarCrop: ?string, removeAvatar: bool, cover: ?UploadedFile, coverCrop: ?string, removeCover: bool} $data */
                $data = $form->getData();
                $images->apply($account, ProfileImage::Avatar, $data['avatar'], $data['avatarCrop'], $data['removeAvatar']);
                $field = 'cover';
                $images->apply($account, ProfileImage::Cover, $data['cover'], $data['coverCrop'], $data['removeCover']);
                $em->flush();
                if (!$remote) {
                    $events->dispatch(new ProfileUpdated($account));
                }
                $this->addFlash('success', \sprintf('Images du profil de @%s enregistrées.', $account->getAcct()));

                return $this->redirectToRoute('admin_accounts', status: Response::HTTP_SEE_OTHER);
            } catch (MediaProcessingException $e) {
                // La photo a pu être remplacée avant l'échec de la couverture : la base doit pointer vers le nouveau fichier.
                if ('cover' === $field) {
                    $em->flush();
                }
                $form->get($field)->addError(new FormError($e->getMessage()));
            }
        }

        return $this->render('admin/account_images.html.twig', ['form' => $form, 'account' => $account, 'remote' => $remote]);
    }

    /**
     * Accorde ou retire un droit (publication, import Instagram). Un administrateur les a toujours.
     */
    #[Route('/admin/comptes/{id}/droits/{right}', name: 'admin_account_right', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function right(string $id, AccountRight $right, Request $request, UserRepository $users, EntityManagerInterface $em): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        $user = $users->find($id);
        if (!$user instanceof User) {
            throw $this->createNotFoundException();
        }
        if (!$this->isCsrfTokenValid('right-'.$id, $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }
        $username = $user->getAccount()->getUsername();
        if ($user->isAdmin()) {
            $this->addFlash('error', \sprintf('@%s est administrateur : il a tous les droits.', $username));
        } else {
            $allow = $request->getPayload()->getBoolean('allow');
            $allow ? $user->grant($right) : $user->revoke($right);
            $em->flush();
            $this->addFlash('success', $right->changedMessage($username, $allow));
        }

        return $this->redirectToRoute('admin_accounts', status: Response::HTTP_SEE_OTHER);
    }

    /**
     * Permet ou retire l'import d'une archive Instagram vers un compte Mastodon connecté.
     */
    #[Route('/admin/comptes-mastodon/{id}/import-instagram', name: 'admin_mastodon_import', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function mastodonImport(string $id, Request $request, MastodonUserRepository $mastodonUsers, EntityManagerInterface $em): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        $user = $mastodonUsers->find($id);
        if (!$user instanceof MastodonUser) {
            throw $this->createNotFoundException();
        }
        if (!$this->isCsrfTokenValid('mastodon-right-'.$id, $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }
        $allow = $request->getPayload()->getBoolean('allow');
        $user->allowInstagramImport($allow);
        $em->flush();
        $acct = $user->getAccount()->getAcct();
        $this->addFlash('success', $allow
            ? \sprintf('@%s peut désormais importer son archive Instagram sur son compte Mastodon.', $acct)
            : \sprintf('@%s ne peut plus importer d’archive Instagram. Ses publications déjà envoyées restent sur son compte.', $acct));

        return $this->redirectToRoute('admin_accounts', status: Response::HTTP_SEE_OTHER);
    }

    /**
     * Supprime un compte de l'instance et tout ce qu'il a publié ; ses abonnés du fédivers en sont prévenus.
     * Un administrateur ne peut pas supprimer son propre compte.
     */
    #[Route('/admin/comptes/{id}/supprimer', name: 'admin_account_delete', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function delete(string $id, Request $request, UserRepository $users, AccountDeletion $deletion): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        $user = $users->find($id);
        if (!$user instanceof User) {
            throw $this->createNotFoundException();
        }
        if (!$this->isCsrfTokenValid('delete-account-'.$id, $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }
        $username = $user->getAccount()->getUsername();
        if ($user === $this->getUser()) {
            $this->addFlash('error', 'Vous ne pouvez pas supprimer votre propre compte.');

            return $this->redirectToRoute('admin_accounts', status: Response::HTTP_SEE_OTHER);
        }
        $deletion->delete($user);
        $this->addFlash('success', \sprintf('Compte @%s supprimé, avec ses publications, ses stories et ses fichiers. Ses abonnés du fédivers en sont prévenus.', $username));

        return $this->redirectToRoute('admin_accounts', status: Response::HTTP_SEE_OTHER);
    }

    /**
     * Retire l'accès d'un compte Mastodon connecté : son jeton est révoqué, la personne est déconnectée. Ses J'aime,
     * commentaires et abonnements restent, comme pour tout compte du fédivers ; elle peut se reconnecter.
     */
    #[Route('/admin/comptes-mastodon/{id}/supprimer', name: 'admin_mastodon_delete', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function deleteMastodon(string $id, Request $request, MastodonUserRepository $mastodonUsers, MastodonLogin $login, EntityManagerInterface $em): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        $user = $mastodonUsers->find($id);
        if (!$user instanceof MastodonUser) {
            throw $this->createNotFoundException();
        }
        if (!$this->isCsrfTokenValid('delete-mastodon-'.$id, $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }
        $acct = $user->getAccount()->getAcct();
        $login->logout($user);
        $em->remove($user);
        $em->flush();
        $this->addFlash('success', \sprintf('Accès de @%s supprimé : la personne est déconnectée de Socialize.', $acct));

        return $this->redirectToRoute('admin_accounts', status: Response::HTTP_SEE_OTHER);
    }
}
