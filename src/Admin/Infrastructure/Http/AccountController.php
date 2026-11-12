<?php

declare(strict_types=1);

namespace App\Admin\Infrastructure\Http;

use App\Core\Application\Account\RegisterAccount;
use App\Core\Application\Account\RegistrationException;
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
            /** @var array{email: string, username: string, displayName: ?string, password: string, publisher: bool} $data */
            $data = $form->getData();
            try {
                $user = $registerAccount->register($data['email'], $data['username'], $data['password'], $data['displayName']);
                if ($data['publisher']) {
                    $user->grantPublishing();
                    $em->flush();
                }
                $this->addFlash('success', \sprintf('Compte @%s créé. Transmettez son mot de passe à la personne : elle pourra le changer dans ses réglages.', $user->getAccount()->getUsername()));

                return $this->redirectToRoute('admin_accounts', status: Response::HTTP_SEE_OTHER);
            } catch (RegistrationException $e) {
                $form->addError(new FormError($e->getMessage()));
            }
        }

        return $this->render('admin/accounts.html.twig', [
            'form' => $form,
            'users' => $users->recent(),
        ]);
    }

    /**
     * Accorde ou retire le droit de publier (publications, stories, imports). Un administrateur l'a toujours.
     */
    #[Route('/admin/comptes/{id}/publication', name: 'admin_account_publishing', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function publishing(string $id, Request $request, UserRepository $users, EntityManagerInterface $em): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        $user = $users->find($id);
        if (!$user instanceof User) {
            throw $this->createNotFoundException();
        }
        if (!$this->isCsrfTokenValid('publishing-'.$id, $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }
        $username = $user->getAccount()->getUsername();
        if ($user->isAdmin()) {
            $this->addFlash('error', \sprintf('@%s est administrateur : il peut toujours publier.', $username));
        } elseif ($request->getPayload()->getBoolean('allow')) {
            $user->grantPublishing();
            $em->flush();
            $this->addFlash('success', \sprintf('@%s peut désormais publier.', $username));
        } else {
            $user->revokePublishing();
            $em->flush();
            $this->addFlash('success', \sprintf('@%s ne peut plus publier. Ses publications existantes restent en ligne.', $username));
        }

        return $this->redirectToRoute('admin_accounts', status: Response::HTTP_SEE_OTHER);
    }
}
