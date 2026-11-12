<?php

declare(strict_types=1);

namespace App\Admin\Infrastructure\Http;

use App\Core\Application\Account\RegisterAccount;
use App\Core\Application\Account\RegistrationException;
use App\Core\Domain\Account\AccountRight;
use App\Core\Domain\Account\User;
use App\Core\Infrastructure\Doctrine\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
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
    public function index(Request $request, RegisterAccount $registerAccount, UserRepository $users, EntityManagerInterface $em): Response
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
                $this->addFlash('success', \sprintf('Compte @%s créé. Transmettez son mot de passe à la personne : elle pourra le changer dans ses réglages.', $user->getAccount()->getUsername()));

                return $this->redirectToRoute('admin_accounts', status: Response::HTTP_SEE_OTHER);
            } catch (RegistrationException $e) {
                $form->addError(new FormError($e->getMessage()));
            }
        }

        return $this->render('admin/accounts.html.twig', [
            'form' => $form,
            'users' => $users->recent(),
            'rights' => AccountRight::cases(),
        ]);
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
}
