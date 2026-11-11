<?php

declare(strict_types=1);

namespace App\Admin\Infrastructure\Http;

use App\Core\Application\Account\RegisterAccount;
use App\Core\Application\Account\RegistrationException;
use App\Core\Domain\Account\User;
use App\Core\Infrastructure\Doctrine\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Espace Administration : comptes de l'instance, et création d'un compte par l'administrateur.
 */
#[IsGranted(User::ROLE_ADMIN)]
final class AccountController extends AbstractController
{
    #[Route('/admin/comptes', name: 'admin_accounts', methods: ['GET', 'POST'])]
    public function index(Request $request, RegisterAccount $registerAccount, UserRepository $users): Response
    {
        $form = $this->createForm(AccountType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{email: string, username: string, displayName: ?string, password: string} $data */
            $data = $form->getData();
            try {
                $user = $registerAccount->register($data['email'], $data['username'], $data['password'], $data['displayName']);
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
}
