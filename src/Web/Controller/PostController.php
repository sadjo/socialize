<?php

declare(strict_types=1);

namespace App\Web\Controller;

use App\Core\Application\Post\PostAccess;
use App\Core\Application\Social\SocialActions;
use App\Core\Domain\Post\Post;
use App\Core\Domain\Post\Visibility;
use App\Core\Infrastructure\Doctrine\CommentRepository;
use App\Core\Infrastructure\Doctrine\LikeRepository;
use App\Core\Infrastructure\Doctrine\PostRepository;
use App\Federation\Application\Serializer;
use App\Federation\Infrastructure\Http\ActivityJsonResponse;
use App\Media\Application\PostComposer;
use App\Web\Form\CommentType;
use App\Web\Security\CurrentAccount;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
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
    public function show(string $id, Request $request, CommentRepository $comments, LikeRepository $likes, Serializer $serializer): Response
    {
        $post = $this->visiblePost($id);

        // Une instance du fédivers demande l'objet Note de la publication.
        if (ActivityJsonResponse::wanted($request)) {
            if ($post->isFederated()) {
                return $this->redirect((string) $post->getApUri());
            }
            if (!\in_array($post->getVisibility(), [Visibility::Public, Visibility::Unlisted], true)) {
                throw new NotFoundHttpException();
            }

            return new ActivityJsonResponse($serializer->withContext($serializer->note($post)));
        }

        return $this->render('post/show.html.twig', [
            'post' => $post,
            'comments' => $comments->forPost($post),
            'liked' => $likes->likedAmong($this->currentAccount->get(), [$post]),
            'comment_form' => $this->createForm(CommentType::class, options: ['action' => $this->generateUrl('app_post_comment', ['id' => $id])]),
        ]);
    }

    #[Route('/p/{id}/aimer', name: 'app_post_like', requirements: ['id' => \Symfony\Component\Routing\Requirement\Requirement::UID_BASE58], methods: ['POST'])]
    public function like(string $id, Request $request, SocialActions $social): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        $post = $this->visiblePost($id);
        $this->checkToken($request, 'like-'.$id);

        $liked = 'double-tap' === $request->request->getString('source')
            ? $this->likeOnce($social, $post)
            : $social->toggleLike($this->currentAccount->require(), $post);

        if (TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
            $request->setRequestFormat(TurboBundle::STREAM_FORMAT);

            return $this->render('post/like.stream.html.twig', ['post' => $post, 'liked' => [$post->getPublicId() => $liked]]);
        }

        return $this->redirect($request->headers->get('referer') ?? $this->generateUrl('app_post_show', ['id' => $id]), Response::HTTP_SEE_OTHER);
    }

    #[Route('/p/{id}/commentaires', name: 'app_post_comment', requirements: ['id' => \Symfony\Component\Routing\Requirement\Requirement::UID_BASE58], methods: ['POST'])]
    public function comment(string $id, Request $request, SocialActions $social): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        $post = $this->visiblePost($id);

        $form = $this->createForm(CommentType::class);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{content: string} $data */
            $data = $form->getData();
            $social->comment($this->currentAccount->require(), $post, $data['content']);
        } else {
            $this->addFlash('error', 'Le commentaire n’a pas été publié : écrivez entre 1 et 1 000 caractères.');
        }

        return $this->redirectToRoute('app_post_show', ['id' => $id, '_fragment' => 'commentaires'], Response::HTTP_SEE_OTHER);
    }

    #[Route('/p/{id}/supprimer', name: 'app_post_delete', requirements: ['id' => \Symfony\Component\Routing\Requirement\Requirement::UID_BASE58], methods: ['POST'])]
    public function delete(string $id, Request $request, PostComposer $composer): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        $post = $this->visiblePost($id);
        if (!$post->isOwnedBy($this->currentAccount->get())) {
            throw $this->createAccessDeniedException('Seule l’autrice ou l’auteur peut supprimer cette publication.');
        }
        $this->checkToken($request, 'delete-'.$id);

        $username = $post->getAccount()->getUsername();
        $composer->delete($post);
        $this->addFlash('success', 'Publication supprimée.');

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

    private function checkToken(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide. Rechargez la page et réessayez.');
        }
    }
}
