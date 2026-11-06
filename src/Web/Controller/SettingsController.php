<?php

declare(strict_types=1);

namespace App\Web\Controller;

use App\Core\Domain\Event\ProfileUpdated;
use App\Media\Application\AvatarUploader;
use App\Media\Application\MediaProcessingException;
use App\Web\Form\ProfileSettingsType;
use App\Web\Security\CurrentAccount;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class SettingsController extends AbstractController
{
    #[Route('/reglages', name: 'app_settings', methods: ['GET', 'POST'])]
    public function profile(Request $request, CurrentAccount $currentAccount, AvatarUploader $avatars, EntityManagerInterface $em, EventDispatcherInterface $events): Response
    {
        $account = $currentAccount->require();
        $form = $this->createForm(ProfileSettingsType::class, [
            'displayName' => $account->getDisplayName(),
            'bio' => $account->getBio(),
            'hideNetwork' => $account->isNetworkHidden(),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{displayName: string, bio: ?string, hideNetwork: bool} $data */
            $data = $form->getData();
            $account->updateProfile($data['displayName'], $data['bio']);
            $account->hideNetwork($data['hideNetwork']);

            try {
                $avatar = $form->get('avatar')->getData();
                if ($avatar instanceof UploadedFile) {
                    $avatars->replace($account, $avatar);
                }
                $em->flush();
                $events->dispatch(new ProfileUpdated($account));
                $this->addFlash('success', 'Profil enregistré.');

                return $this->redirectToRoute('app_settings', status: Response::HTTP_SEE_OTHER);
            } catch (MediaProcessingException $e) {
                $form->get('avatar')->addError(new FormError($e->getMessage()));
            }
        }

        return $this->render('settings/profile.html.twig', ['form' => $form, 'account' => $account]);
    }
}
