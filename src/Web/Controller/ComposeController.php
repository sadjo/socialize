<?php

declare(strict_types=1);

namespace App\Web\Controller;

use App\Core\Application\Social\AudienceGroups;
use App\Core\Domain\Account\User;
use App\Core\Domain\Post\CrosspostTarget;
use App\Core\Domain\Post\Visibility;
use App\Core\Domain\Social\AudienceGroup;
use App\Crosspost\Meta\Application\InstagramConnections;
use App\Integration\Openverse\Application\MusicLibrary;
use App\Integration\Openverse\Application\OpenverseException;
use App\Media\Application\Library\LibraryPublisher;
use App\Media\Application\MediaProcessingException;
use App\Web\Form\ComposeKind;
use App\Web\Form\ComposePostType;
use App\Web\Form\CrosspostField;
use App\Web\Security\CurrentAccount;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(User::ROLE_PUBLISHER, message: 'Votre compte n’a pas le droit de publier.')]
final class ComposeController extends AbstractController
{
    #[Route('/publier', name: 'app_compose', methods: ['GET', 'POST'])]
    public function __invoke(Request $request, LibraryPublisher $publisher, CurrentAccount $currentAccount, ComposeSources $sources, MusicLibrary $music, AudienceGroups $groups, InstagramConnections $instagram): Response
    {
        $instagramConnection = $instagram->usable($currentAccount->require());
        $kind = ComposeKind::fromQuery($request->query->getString('type') ?: null);
        $form = $this->createForm(ComposePostType::class, options: ['groups' => $groups->of($currentAccount->require()), 'instagram' => $instagramConnection, 'kind' => $kind]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{kind: string, media: list<UploadedFile>, order: ?string, altTexts: array<int|string, ?string>, caption: ?string, location: ?string, visibility: Visibility, contentWarning: ?string, music: array{track: ?string, start: ?string}, audienceGroups: list<AudienceGroup>, crosspostInstagram?: bool} $data */
            $data = $form->getData();
            // Onglet « Texte » : publication texte, sans média, ni musique, ni Instagram.
            $text = ComposeKind::TEXT === $data['kind'];
            $order = $text ? [] : ComposeSources::order($data['order']);
            $files = $text ? [] : $data['media'];

            try {
                if (!$text && [] === $order && [] === $files) {
                    throw new MediaProcessingException('Ajoutez au moins une photo ou une vidéo, ou choisissez l’onglet « Texte ».');
                }
                $post = $publisher->publishPost(
                    $currentAccount->require(),
                    $order,
                    $files,
                    ComposeSources::altTexts($data['altTexts']),
                    $data['caption'] ?? '',
                    $data['visibility'],
                    $data['contentWarning'],
                    $data['location'],
                    $text ? null : $music->choice($data['music']['track'], (int) $data['music']['start']),
                    Visibility::Groups === $data['visibility'] ? $data['audienceGroups'] : [],
                    !$text && true === ($data[CrosspostField::NAME] ?? false) ? [CrosspostTarget::Instagram] : [],
                );
                if ($post->isPending()) {
                    // Vidéo en conversion : la publication attend sur le profil, avec la progression.
                    $this->addFlash('success', 'Publication en préparation : elle paraîtra, et partira dans le fédivers, dès que la vidéo sera convertie.');

                    return $this->redirectToRoute('app_profile', ['username' => $post->getAccount()->getUsername()], Response::HTTP_SEE_OTHER);
                }
                $this->addFlash('success', 'Publié.');

                return $this->redirectToRoute('app_post_show', ['id' => $post->getPublicId()], Response::HTTP_SEE_OTHER);
            } catch (MediaProcessingException $e) {
                $form->get($text ? 'caption' : 'media')->addError(new FormError($e->getMessage()));
            } catch (OpenverseException $e) {
                $form->addError(new FormError($e->getMessage()));
            }
        }

        return $this->render('compose/new.html.twig', ['form' => $form, 'kind' => $form->get('kind')->getData() ?? $kind, 'instagram' => $instagramConnection, 'crosspost_defaults' => CrosspostField::defaults($instagramConnection, false)] + $sources->forCurrentAccount());
    }
}
