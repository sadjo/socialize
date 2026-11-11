<?php

declare(strict_types=1);

namespace App\Federation\Infrastructure\Http;

use App\Core\Domain\Account\Account;
use App\Core\Domain\Post\Visibility;
use App\Core\Domain\PublicId;
use App\Core\Infrastructure\Doctrine\AccountRepository;
use App\Core\Infrastructure\Doctrine\CommentRepository;
use App\Core\Infrastructure\Doctrine\FollowRepository;
use App\Core\Infrastructure\Doctrine\PostRepository;
use App\Federation\Application\ApUrls;
use App\Federation\Application\Serializer;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Acteurs ActivityPub des comptes locaux, leur boîte d'envoi et leurs collections.
 */
#[AsController]
final readonly class ActorController
{
    private const string USERNAME = '[a-z0-9_](?:[a-z0-9_.]{0,28}[a-z0-9_])?';
    private const int OUTBOX_PAGE = 20;

    public function __construct(
        private AccountRepository $accounts,
        private Serializer $serializer,
        private ApUrls $urls,
    ) {
    }

    #[Route('/users/{username}', name: 'ap_actor', requirements: ['username' => self::USERNAME], methods: ['GET'])]
    public function actor(string $username, Request $request): Response
    {
        $account = $this->account($username);
        if (!ActivityJsonResponse::wanted($request)) {
            return new RedirectResponse($this->urls->profile($account));
        }

        return new ActivityJsonResponse($this->serializer->actor($account));
    }

    #[Route('/actor', name: 'ap_instance_actor', methods: ['GET'])]
    public function instance(): ActivityJsonResponse
    {
        return new ActivityJsonResponse($this->serializer->instanceActor());
    }

    /**
     * Boîte d'envoi de l'acteur de l'instance, annoncée dans son profil (Mastodon la lit) : il ne publie rien.
     */
    #[Route('/actor/outbox', name: 'ap_instance_outbox', methods: ['GET'])]
    public function instanceOutbox(): ActivityJsonResponse
    {
        return new ActivityJsonResponse([
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => $this->serializer->instanceActor()['outbox'],
            'type' => 'OrderedCollection',
            'totalItems' => 0,
            'orderedItems' => [],
        ]);
    }

    #[Route('/users/{username}/outbox', name: 'ap_outbox', requirements: ['username' => self::USERNAME], methods: ['GET'])]
    public function outbox(string $username, Request $request, PostRepository $posts): ActivityJsonResponse
    {
        $account = $this->account($username);
        $id = $this->urls->outbox($account);
        $total = $posts->countForProfile($account, [Visibility::Public]);

        if (!$request->query->has('page')) {
            return new ActivityJsonResponse($this->serializer->withContext([
                'id' => $id,
                'type' => 'OrderedCollection',
                'totalItems' => $total,
                'first' => $id.'?page=1',
            ]));
        }

        $items = [];
        foreach ($posts->profileTimeline($account, [Visibility::Public], null, self::OUTBOX_PAGE)->posts as $post) {
            $note = $this->serializer->note($post);
            $items[] = ['id' => $this->urls->note($post).'/activity', 'type' => 'Create', 'actor' => $note['attributedTo'], 'published' => $note['published'], 'to' => $note['to'], 'cc' => $note['cc'], 'object' => $note];
        }

        return new ActivityJsonResponse($this->serializer->withContext([
            'id' => $id.'?page=1',
            'type' => 'OrderedCollectionPage',
            'partOf' => $id,
            'totalItems' => $total,
            'orderedItems' => $items,
        ]));
    }

    #[Route('/users/{username}/followers', name: 'ap_followers', requirements: ['username' => self::USERNAME], methods: ['GET'])]
    public function followers(string $username, FollowRepository $follows): ActivityJsonResponse
    {
        $account = $this->account($username);

        // Le nombre est public, pas la liste.
        return new ActivityJsonResponse($this->serializer->withContext(['id' => $this->urls->followers($account), 'type' => 'OrderedCollection', 'totalItems' => $follows->countFollowers($account)]));
    }

    #[Route('/users/{username}/following', name: 'ap_following', requirements: ['username' => self::USERNAME], methods: ['GET'])]
    public function following(string $username, FollowRepository $follows): ActivityJsonResponse
    {
        $account = $this->account($username);

        return new ActivityJsonResponse($this->serializer->withContext(['id' => $this->urls->following($account), 'type' => 'OrderedCollection', 'totalItems' => $follows->countFollowing($account)]));
    }

    #[Route('/c/{id}', name: 'ap_comment', requirements: ['id' => \Symfony\Component\Routing\Requirement\Requirement::UID_BASE58], methods: ['GET'])]
    public function comment(string $id, Request $request, CommentRepository $comments): Response
    {
        $uuid = PublicId::decode($id);
        $comment = $uuid instanceof \Symfony\Component\Uid\Uuid ? $comments->find($uuid) : null;
        if (null === $comment || Visibility::Public !== $comment->getPost()->getVisibility() && Visibility::Unlisted !== $comment->getPost()->getVisibility()) {
            throw new NotFoundHttpException();
        }
        if (!ActivityJsonResponse::wanted($request)) {
            return new RedirectResponse($this->urls->base().'/p/'.$comment->getPost()->getPublicId().'#commentaires');
        }

        return new ActivityJsonResponse($this->serializer->withContext($this->serializer->commentNote($comment)));
    }

    private function account(string $username): Account
    {
        return $this->accounts->findLocal($username) ?? throw new NotFoundHttpException('Compte inconnu.');
    }
}
