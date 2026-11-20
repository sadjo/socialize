<?php

declare(strict_types=1);

namespace App\Web\Controller;

use App\Core\Application\Post\PostAccess;
use App\Core\Application\Post\PostArchiver;
use App\Core\Application\Social\AudienceGroups;
use App\Core\Application\Social\PilotedActionFailed;
use App\Core\Application\Social\PilotedActions;
use App\Core\Application\Social\SocialActions;
use App\Core\Domain\Media\MediaAttachment;
use App\Core\Domain\Post\Post;
use App\Core\Domain\Post\Visibility;
use App\Core\Infrastructure\Doctrine\CommentRepository;
use App\Core\Infrastructure\Doctrine\LikeRepository;
use App\Core\Infrastructure\Doctrine\PostRepository;
use App\Federation\Application\Serializer;
use App\Federation\Infrastructure\Http\ActivityJsonResponse;
use App\Integration\Openverse\Application\MusicLibrary;
use App\Integration\Openverse\Application\OpenverseException;
use App\Media\Application\MediaProcessingException;
use App\Media\Application\MediaUrls;
use App\Media\Application\PostComposer;
use App\Media\Application\PostEditor;
use App\Web\Form\EditPostType;
use App\Web\Security\CurrentAccount;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\UX\Turbo\TurboBundle;

final class PostController extends AbstractController
{
    public function __construct(
        private readonly PostRepository $posts,
        private readonly PostAccess $access,
        private readonly CurrentAccount $currentAccount,
    ) {
    }

    #[Route('/p/{id}', name: 'app_post_show', requirements: ['id' => \Symfony\Component\Routing\Requirement\Requirement::UID_BASE58], methods: ['GET'])]
    #[Route('/p/{id}/v{revision}', name: 'app_post_show_revision', requirements: ['id' => \Symfony\Component\Routing\Requirement\Requirement::UID_BASE58, 'revision' => '[2-9]|[1-9]\d+'], methods: ['GET'])]
    public function show(string $id, Request $request, CommentRepository $comments, LikeRepository $likes, Serializer $serializer, int $revision = 1): Response
    {
        $post = $this->visiblePost($id);

        // Une instance du fédivers demande l'objet Note de la publication.
        if (ActivityJsonResponse::wanted($request)) {
            if ($post->isFederated()) {
                return $this->redirect((string) $post->getApUri());
            }
            // Seul l'identifiant courant existe : les anciens restent supprimés pour le fédivers.
            // Seule une publication publique se lit sans droit : non répertoriée ou réservée, elle reste sur l'instance.
            if ($revision !== $post->getApRevision() || Visibility::Public !== $post->getVisibility()) {
                throw new NotFoundHttpException();
            }

            return new ActivityJsonResponse($serializer->withContext($serializer->note($post)));
        }

        return $this->render('post/show.html.twig', [
            'post' => $post,
            'comments' => $comments->forPost($post),
            'liked' => $likes->likedAmong($this->currentAccount->get(), [$post]),
        ]);
    }

    /**
     * Personnes qui ont aimé la publication (chargée dans la fenêtre ouverte par le compteur de « J'aime »).
     */
    #[Route('/p/{id}/jaime', name: 'app_post_likers', requirements: ['id' => \Symfony\Component\Routing\Requirement\Requirement::UID_BASE58], methods: ['GET'])]
    public function likers(string $id, LikeRepository $likes): Response
    {
        $post = $this->visiblePost($id);

        return $this->render('post/_likers.html.twig', ['post' => $post, 'likers' => $likes->likersOf($post)]);
    }

    #[Route('/p/{id}/aimer', name: 'app_post_like', requirements: ['id' => \Symfony\Component\Routing\Requirement\Requirement::UID_BASE58], methods: ['POST'])]
    public function like(string $id, Request $request, SocialActions $social, LikeRepository $likes): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        $post = $this->visiblePost($id);
        $this->checkToken($request, 'like-'.$id);

        try {
            $liked = 'double-tap' === $request->request->getString('source')
                ? $this->likeOnce($social, $post)
                : $social->toggleLike($this->currentAccount->require(), $post);
        } catch (PilotedActionFailed $e) {
            // Compte Mastodon : son serveur a refusé, rien n'a changé.
            $this->addFlash('error', $e->getMessage());
            $liked = $likes->findOneFor($this->currentAccount->require(), $post) instanceof \App\Core\Domain\Social\Like;
        }

        if (TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
            $request->setRequestFormat(TurboBundle::STREAM_FORMAT);

            return $this->render('post/like.stream.html.twig', ['post' => $post, 'liked' => [$post->getPublicId() => $liked]]);
        }

        return $this->redirect($request->headers->get('referer') ?? $this->generateUrl('app_post_show', ['id' => $id]), Response::HTTP_SEE_OTHER);
    }

    #[Route('/p/{id}/commentaires', name: 'app_post_comment', requirements: ['id' => \Symfony\Component\Routing\Requirement\Requirement::UID_BASE58], methods: ['POST'])]
    public function comment(string $id, Request $request, SocialActions $social, LikeRepository $likes): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        $post = $this->visiblePost($id);
        $this->checkToken($request, 'comment-'.$id);

        $comment = null;
        $error = null;
        try {
            $comment = $social->comment($this->currentAccount->require(), $post, $request->request->getString('content'));
        } catch (\DomainException $e) {
            $error = $e->getMessage();
        }

        // Depuis le fil ou la page de la publication : le commentaire apparaît sans recharger la page.
        if (TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
            $request->setRequestFormat(TurboBundle::STREAM_FORMAT);

            return $this->render('comment/added.stream.html.twig', [
                'post' => $post,
                'comment' => $comment,
                'error' => $error,
                'compact' => !str_contains((string) $request->headers->get('referer'), '/p/'.$id),
                'liked' => $likes->likedAmong($this->currentAccount->get(), [$post]),
            ]);
        }
        if (null !== $error) {
            $this->addFlash('error', $error);
        }

        return $this->redirectToRoute('app_post_show', ['id' => $id, '_fragment' => 'commentaires'], Response::HTTP_SEE_OTHER);
    }

    #[Route('/c/{id}/supprimer', name: 'app_comment_delete', requirements: ['id' => \Symfony\Component\Routing\Requirement\Requirement::UID_BASE58], methods: ['POST'])]
    public function deleteComment(string $id, Request $request, SocialActions $social, CommentRepository $comments, LikeRepository $likes): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        $comment = $comments->findByPublicId($id) ?? throw new NotFoundHttpException('Ce commentaire n’existe pas.');
        $post = $this->visiblePost($comment->getPost()->getPublicId());
        if (!$comment->isDeletableBy($this->currentAccount->get())) {
            throw $this->createAccessDeniedException('Seul l’auteur du commentaire ou de la publication peut le supprimer.');
        }
        $this->checkToken($request, 'delete-comment-'.$id);

        try {
            $social->deleteComment($comment, $this->currentAccount->get());
        } catch (PilotedActionFailed $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('app_post_show', ['id' => $post->getPublicId(), '_fragment' => 'commentaires'], Response::HTTP_SEE_OTHER);
        }

        if (TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
            $request->setRequestFormat(TurboBundle::STREAM_FORMAT);

            return $this->render('comment/deleted.stream.html.twig', ['post' => $post, 'comment_id' => $id, 'liked' => $likes->likedAmong($this->currentAccount->get(), [$post])]);
        }

        return $this->redirectToRoute('app_post_show', ['id' => $post->getPublicId(), '_fragment' => 'commentaires'], Response::HTTP_SEE_OTHER);
    }

    #[Route('/p/{id}/modifier', name: 'app_post_edit', requirements: ['id' => \Symfony\Component\Routing\Requirement\Requirement::UID_BASE58], methods: ['GET', 'POST'])]
    public function edit(string $id, Request $request, PostEditor $editor, MediaUrls $urls, MusicLibrary $music, AudienceGroups $groups): Response
    {
        $post = $this->visiblePost($id);
        if (!$post->isOwnedBy($this->currentAccount->get()) || $post->isFederated()) {
            throw $this->createAccessDeniedException('Seule l’autrice ou l’auteur peut modifier cette publication.');
        }

        // Publication texte (sans média, parue) : le même formulaire qu'à sa création, sans médias ni musique.
        $text = $post->getMedia()->isEmpty() && !$post->isPending();
        $existing = [];
        $altTexts = [];
        foreach ($post->getMedia() as $media) {
            $token = PostEditor::token($media);
            $existing[] = [
                'token' => $token,
                'src' => $urls->derivative($media, MediaAttachment::DERIVATIVE_THUMB) ?? $urls->derivative($media, MediaAttachment::DERIVATIVE_LARGE),
                // Loupe de l'aperçu : copie en grand (affiche pour une vidéo) et vidéo.
                'large' => $urls->derivative($media, $media->isVideo() ? MediaAttachment::DERIVATIVE_POSTER : MediaAttachment::DERIVATIVE_LARGE),
                'videoSrc' => $media->isVideo() ? $urls->derivative($media, MediaAttachment::DERIVATIVE_VIDEO) : null,
                'video' => $media->isVideo(),
                'alt' => $media->getAltText(),
            ];
            $altTexts[$token] = $media->getAltText();
        }

        $form = $this->createForm(EditPostType::class, [
            'caption' => $post->getCaption(),
            'location' => $post->getLocation(),
            'visibility' => $post->getVisibility(),
            'contentWarning' => $post->getContentWarning(),
            'order' => implode(',', array_column($existing, 'token')),
            'altTexts' => $altTexts,
            'music' => ['track' => $post->getMusic()?->getOpenverseId(), 'start' => (string) $post->getMusicStart()],
            'audienceGroups' => $post->getAudienceGroups(),
            'publishedAt' => $post->getPublishedAt(),
        ], ['groups' => $groups->of($post->getAccount()), 'can_redate' => $this->isGranted('ROLE_ADMIN') && !$post->isPending(), 'text' => $text]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{media?: list<UploadedFile>|null, altTexts?: array<string, ?string>|null, order?: string, caption: ?string, location: ?string, visibility: Visibility, contentWarning: ?string, music?: array{track: ?string, start: ?string}, audienceGroups: list<\App\Core\Domain\Social\AudienceGroup>, publishedAt?: \DateTimeImmutable} $data */
            $data = $form->getData();
            try {
                $editor->edit(
                    $post,
                    $data['caption'] ?? '',
                    $data['visibility'],
                    $data['contentWarning'],
                    $data['location'],
                    array_values(array_filter(explode(',', $data['order'] ?? ''))),
                    $data['media'] ?? [],
                    $data['altTexts'] ?? [],
                    isset($data['music']) ? $music->choice($data['music']['track'], (int) $data['music']['start']) : null,
                    Visibility::Groups === $data['visibility'] ? $data['audienceGroups'] : [],
                    $data['publishedAt'] ?? null,
                );
                $this->addFlash('success', 'Publication modifiée.');

                return $this->redirectToRoute('app_post_show', ['id' => $id], Response::HTTP_SEE_OTHER);
            } catch (MediaProcessingException|OpenverseException $e) {
                $form->addError(new FormError($e->getMessage()));
            }
        }

        return $this->render('post/edit.html.twig', ['post' => $post, 'form' => $form, 'existing' => $existing, 'text' => $text]);
    }

    #[Route('/p/{id}/archiver', name: 'app_post_archive', requirements: ['id' => \Symfony\Component\Routing\Requirement\Requirement::UID_BASE58], methods: ['POST'])]
    public function archive(string $id, Request $request, PostArchiver $archiver): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        $post = $this->ownPost($id, $request, 'archive-'.$id);
        try {
            $archiver->archive($post);
        } catch (\DomainException $e) {
            throw $this->createAccessDeniedException($e->getMessage(), $e);
        }
        $this->addFlash('success', 'Publication archivée : elle n’est plus visible que par vous, dans vos archives.');

        // Depuis la page de la publication, on y reste ; depuis un fil, on y revient.
        return $this->redirect($request->headers->get('referer') ?? $this->generateUrl('app_post_show', ['id' => $id]), Response::HTTP_SEE_OTHER);
    }

    #[Route('/p/{id}/desarchiver', name: 'app_post_unarchive', requirements: ['id' => \Symfony\Component\Routing\Requirement\Requirement::UID_BASE58], methods: ['POST'])]
    public function unarchive(string $id, Request $request, PostArchiver $archiver): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        $post = $this->ownPost($id, $request, 'archive-'.$id);
        $archiver->unarchive($post);
        $this->addFlash('success', 'Publication sortie des archives : elle retrouve sa visibilité « '.$post->getVisibility()->label().' ».');

        return $this->redirect($request->headers->get('referer') ?? $this->generateUrl('app_post_show', ['id' => $id]), Response::HTTP_SEE_OTHER);
    }

    #[Route('/p/{id}/supprimer', name: 'app_post_delete', requirements: ['id' => \Symfony\Component\Routing\Requirement\Requirement::UID_BASE58], methods: ['POST'])]
    public function delete(string $id, Request $request, PostComposer $composer, \App\Crosspost\Meta\Application\InstagramPublishing $instagramNotice, PilotedActions $piloted): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        $post = $this->visiblePost($id);
        if (!$post->isOwnedBy($this->currentAccount->get())) {
            throw $this->createAccessDeniedException('Seule l’autrice ou l’auteur peut supprimer cette publication.');
        }
        $this->checkToken($request, 'delete-'.$id);

        $username = $post->getAccount()->getAcct();
        if (!$post->getAccount()->isLocal()) {
            // Compte Mastodon : la publication est d'abord supprimée sur son serveur.
            try {
                $piloted->deletePost($post);
            } catch (PilotedActionFailed $e) {
                $this->addFlash('error', $e->getMessage());

                return $this->redirectToRoute('app_post_show', ['id' => $id], Response::HTTP_SEE_OTHER);
            }
        }
        $instagram = $instagramNotice->forDeletion($post);
        $composer->delete($post);
        $this->addFlash('success', 'Publication supprimée.'.(null !== $instagram ? ' '.$instagram : ''));

        // Depuis un fil, on y reste ; depuis la page de la publication, retour au profil.
        $referer = (string) $request->headers->get('referer');
        if ('' !== $referer && !str_contains($referer, '/p/'.$id) && str_starts_with($referer, $request->getSchemeAndHttpHost().'/')) {
            return $this->redirect($referer, Response::HTTP_SEE_OTHER);
        }

        return $this->redirectToRoute('app_profile', ['username' => $username], Response::HTTP_SEE_OTHER);
    }

    private function likeOnce(SocialActions $social, Post $post): bool
    {
        $social->like($this->currentAccount->require(), $post);

        return true;
    }

    private function visiblePost(string $id): Post
    {
        $post = $this->posts->findByPublicId($id);
        if (!$post instanceof Post || !$this->access->canView($post, $this->currentAccount->get())) {
            throw new NotFoundHttpException('Cette publication n’existe pas ou n’est pas visible.');
        }

        return $post;
    }

    private function ownPost(string $id, Request $request, string $tokenId): Post
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        $post = $this->visiblePost($id);
        if (!$post->isOwnedBy($this->currentAccount->get())) {
            throw $this->createAccessDeniedException('Seule l’autrice ou l’auteur peut archiver cette publication.');
        }
        $this->checkToken($request, $tokenId);

        return $post;
    }

    private function checkToken(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide. Rechargez la page et réessayez.');
        }
    }
}
