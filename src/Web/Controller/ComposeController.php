<?php

declare(strict_types=1);

namespace App\Web\Controller;

use App\Core\Domain\Post\Visibility;
use App\Media\Application\MediaProcessingException;
use App\Media\Application\PostComposer;
use App\Web\Form\ComposePostType;
use App\Web\Security\CurrentAccount;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ComposeController extends AbstractController
{
    #[Route('/publier', name: 'app_compose', methods: ['GET', 'POST'])]
    public function __invoke(Request $request, PostComposer $composer, CurrentAccount $currentAccount): Response
    {
        $form = $this->createForm(ComposePostType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{media: list<UploadedFile>, altTexts: array<int, ?string>, caption: ?string, location: ?string, visibility: Visibility, contentWarning: ?string} $data */
            $data = $form->getData();
            $uploads = [];
            foreach ($data['media'] as $i => $file) {
                $uploads[] = ['file' => $file, 'alt' => $data['altTexts'][$i] ?? null];
            }

            try {
                $post = $composer->publish(
                    $currentAccount->require(),
                    $data['caption'] ?? '',
                    $uploads,
                    $data['visibility'],
                    $data['contentWarning'],
                    $data['location'],
                );
                $this->addFlash('success', $post->hasVideo()
                    ? 'Publié. La vidéo est en cours de préparation, elle apparaîtra dans quelques instants.'
                    : 'Publié.');

                return $this->redirectToRoute('app_post_show', ['id' => $post->getPublicId()], Response::HTTP_SEE_OTHER);
            } catch (MediaProcessingException $e) {
                $form->get('media')->addError(new FormError($e->getMessage()));
            }
        }

        return $this->render('compose/new.html.twig', ['form' => $form]);
    }
}
