<?php

declare(strict_types=1);

namespace App\Web\Controller;

use App\Core\Domain\Account\Theme;
use App\Core\Domain\Account\User;
use App\Core\Domain\Event\ProfileUpdated;
use App\Media\Application\MediaProcessingException;
use App\Media\Application\ProfileImage;
use App\Media\Application\ProfileImages;
use App\Web\Form\AppearanceSettingsType;
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
    public function profile(Request $request, CurrentAccount $currentAccount, ProfileImages $images, EntityManagerInterface $em, EventDispatcherInterface $events): Response
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

            $field = 'avatar';
            try {
                $images->apply($account, ProfileImage::Avatar, $this->uploaded($form->get('avatar')->getData()), $this->crop($form->get('avatarCrop')->getData()));
                $field = 'cover';
                $images->apply($account, ProfileImage::Cover, $this->uploaded($form->get('cover')->getData()), $this->crop($form->get('coverCrop')->getData()), true === $form->get('removeCover')->getData());
                $em->flush();
                $events->dispatch(new ProfileUpdated($account));
                $this->addFlash('success', 'Profil enregistré.');

                return $this->redirectToRoute('app_settings', status: Response::HTTP_SEE_OTHER);
            } catch (MediaProcessingException $e) {
                // La photo de profil a pu être remplacée avant l'échec de la couverture : son ancien fichier n'existe
                // plus, la base doit pointer vers le nouveau.
                if ('cover' === $field) {
                    $em->flush();
                }
                $form->get($field)->addError(new FormError($e->getMessage()));
            }
        }

        return $this->render('settings/profile.html.twig', ['form' => $form, 'account' => $account]);
    }

    /**
     * Thème de l'interface, propre à chaque personne (un compte Mastodon garde le thème automatique).
     */
    #[Route('/reglages/apparence', name: 'app_settings_appearance', methods: ['GET', 'POST'])]
    public function appearance(Request $request, EntityManagerInterface $em): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Réglage réservé aux comptes de cette instance.');
        }
        $form = $this->createForm(AppearanceSettingsType::class, ['theme' => $user->getTheme()]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{theme: Theme} $data */
            $data = $form->getData();
            $user->chooseTheme($data['theme']);
            $em->flush();
            $this->addFlash('success', 'Thème enregistré.');

            return $this->redirectToRoute('app_settings_appearance', status: Response::HTTP_SEE_OTHER);
        }

        return $this->render('settings/appearance.html.twig', ['form' => $form, 'account' => $user->getAccount()]);
    }

    private function uploaded(mixed $data): ?UploadedFile
    {
        return $data instanceof UploadedFile ? $data : null;
    }

    private function crop(mixed $data): ?string
    {
        return \is_string($data) && '' !== $data ? $data : null;
    }
}
