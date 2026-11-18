<?php

declare(strict_types=1);

namespace App\Web\Controller;

use App\Core\Application\Social\SocialActions;
use App\Core\Domain\Account\MediaFilter;
use App\Core\Domain\Account\Theme;
use App\Core\Domain\Account\User;
use App\Core\Domain\Event\ProfileUpdated;
use App\Core\Domain\Post\Visibility;
use App\Core\Infrastructure\Doctrine\FollowRepository;
use App\Core\Infrastructure\Doctrine\PostRepository;
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
    public function profile(Request $request, CurrentAccount $currentAccount, ProfileImages $images, EntityManagerInterface $em, EventDispatcherInterface $events, SocialActions $social, FollowRepository $follows): Response
    {
        $account = $currentAccount->require();
        $form = $this->createForm(ProfileSettingsType::class, [
            'displayName' => $account->getDisplayName(),
            'bio' => $account->getBio(),
            'hideNetwork' => $account->isNetworkHidden(),
            'approveFollowers' => $account->manuallyApprovesFollowers(),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{displayName: string, bio: ?string, hideNetwork: bool, approveFollowers: bool} $data */
            $data = $form->getData();
            $account->updateProfile($data['displayName'], $data['bio']);
            $account->hideNetwork($data['hideNetwork']);
            $approvedBefore = $account->manuallyApprovesFollowers();
            $account->requireFollowApproval($data['approveFollowers']);

            $field = 'avatar';
            try {
                $images->apply($account, ProfileImage::Avatar, $this->uploaded($form->get('avatar')->getData()), $this->crop($form->get('avatarCrop')->getData()));
                $field = 'cover';
                $images->apply($account, ProfileImage::Cover, $this->uploaded($form->get('cover')->getData()), $this->crop($form->get('coverCrop')->getData()), true === $form->get('removeCover')->getData());
                $em->flush();
                $events->dispatch(new ProfileUpdated($account));
                // Validation désactivée : les demandes en attente sont acceptées, comme le ferait Mastodon.
                $accepted = $approvedBefore && !$account->manuallyApprovesFollowers() ? $social->acceptAllFollowers($account) : 0;
                $this->addFlash('success', 'Profil enregistré.'.($accepted > 0 ? \sprintf(' %d demande%s d’abonnement acceptée%2$s.', $accepted, $accepted > 1 ? 's' : '') : ''));

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

        return $this->render('settings/profile.html.twig', ['form' => $form, 'account' => $account, 'pending_requests' => $follows->countPendingRequests($account)]);
    }

    /** Vignettes de ses propres publications, pour voir l'effet du filtre des médias. */
    private const int FILTER_SAMPLES = 3;

    /**
     * Thème de l'interface et filtre des médias, propres à chaque personne (un compte Mastodon garde les valeurs par
     * défaut : thème automatique, filtre léger).
     */
    #[Route('/reglages/apparence', name: 'app_settings_appearance', methods: ['GET', 'POST'])]
    public function appearance(Request $request, EntityManagerInterface $em, PostRepository $posts): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Réglage réservé aux comptes de cette instance.');
        }
        $form = $this->createForm(AppearanceSettingsType::class, ['theme' => $user->getTheme(), 'mediaFilter' => $user->getMediaFilter()]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{theme: Theme, mediaFilter: MediaFilter} $data */
            $data = $form->getData();
            $user->chooseTheme($data['theme']);
            $user->chooseMediaFilter($data['mediaFilter']);
            $em->flush();
            $this->addFlash('success', 'Apparence enregistrée.');

            return $this->redirectToRoute('app_settings_appearance', status: Response::HTTP_SEE_OTHER);
        }

        $account = $user->getAccount();
        $samples = $posts->profileTimeline($account, Visibility::cases(), null, self::FILTER_SAMPLES, $account)->posts;

        return $this->render('settings/appearance.html.twig', ['form' => $form, 'account' => $account, 'samples' => $samples]);
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
