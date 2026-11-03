<?php

declare(strict_types=1);

namespace App\Web\Controller;

use App\Media\Application\AvatarUploader;
use App\Media\Application\MediaProcessingException;
use App\Web\Form\ProfileSettingsType;
use App\Web\Security\CurrentAccount;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class SettingsController extends AbstractController
{
    #[Route('/reglages', name: 'app_settings', methods: ['GET', 'POST'])]
    public function profile(Request $request, CurrentAccount $currentAccount, AvatarUploader $avatars, EntityManagerInterface $em): Response
    {
        $account = $currentAccount->require();
        $form = $this->createForm(ProfileSettingsType::class, [
            'displayName' => $account->getDisplayName(),
            'bio' => $account->getBio(),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{displayName: string, bio: ?string} $data */
            $data = $form->getData();
            $account->updateProfile($data['displayName'], $data['bio']);

            try {
                $avatar = $form->get('avatar')->getData();
                if ($avatar instanceof UploadedFile) {
                    $avatars->replace($account, $avatar);
                }
                $em->flush();
                $this->addFlash('success', 'Profil enregistré.');

                return $this->redirectToRoute('app_settings', status: Response::HTTP_SEE_OTHER);
            } catch (MediaProcessingException $e) {
                $form->get('avatar')->addError(new FormError($e->getMessage()));
            }
        }

        return $this->render('settings/profile.html.twig', ['form' => $form, 'account' => $account]);
    }
}
