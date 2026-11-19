<?php

declare(strict_types=1);

namespace App\Web\Controller;

use App\Core\Application\Feed\Cursor;
use App\Core\Application\Post\PostAccess;
use App\Core\Application\Social\NetworkAccess;
use App\Core\Application\Social\PilotedActionFailed;
use App\Core\Application\Social\SocialActions;
use App\Core\Application\Story\StoryFeed;
use App\Core\Domain\Account\Account;
use App\Core\Domain\Story\Highlight;
use App\Core\Infrastructure\Doctrine\AccountRepository;
use App\Core\Infrastructure\Doctrine\FollowRepository;
use App\Core\Infrastructure\Doctrine\HighlightRepository;
use App\Core\Infrastructure\Doctrine\LikeRepository;
use App\Core\Infrastructure\Doctrine\PostRepository;
use App\Federation\Application\RemoteAccounts;
use App\Federation\Infrastructure\FederationException;
use App\Web\Feed\CommentPreviews;
use App\Web\Feed\NextPage;
use App\Web\Security\CurrentAccount;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class ProfileController extends AbstractController
{
    private const int PAGE_SIZE = 24;
    private const int FEED_PAGE_SIZE = 10;
    private const int FEED_NEWER = 20;
    private const int NETWORK_PAGE_SIZE = 30;
    /** « camille » ou « leo@pixelfed.social ». */
    private const string ACCT = '[A-Za-z0-9_](?:[A-Za-z0-9_.\-]{0,98}[A-Za-z0-9_])?(?:@[a-z0-9\-]+(?:\.[a-z0-9\-]+)+)?';

    public function __construct(
        private readonly AccountRepository $accounts,
        private readonly CurrentAccount $currentAccount,
        private readonly RemoteAccounts $remote,
    ) {
    }

    #[Route('/@{username}', name: 'app_profile', requirements: ['username' => self::ACCT], methods: ['GET'])]
    public function show(string $username, Request $request, PostRepository $posts, FollowRepository $follows, PostAccess $access, StoryFeed $stories, HighlightRepository $highlights, NetworkAccess $network): Response
    {
        $account = $this->account($username, resolve: true);
        $viewer = $this->currentAccount->get();
        $visible = $access->visibleFor($account, $viewer);
        $page = $posts->profileTimeline($account, $visible, Cursor::fromString($request->query->getString('apres') ?: null), self::PAGE_SIZE, $viewer);

        // Page suivante de la grille, chargée au défilement : seulement les vignettes, sans le reste du profil.
        if (NextPage::requested($request)) {
            return $this->render('profile/_grid_page.stream.html.twig', ['account' => $account, 'page' => $page]);
        }

        $isOwner = $viewer instanceof Account && $viewer->getId()->equals($account->getId());
        $parameters = [
            'account' => $account,
            'page' => $page,
            'is_owner' => $isOwner,
            // Publications de l'auteur en attente de la conversion de leurs vidéos (bloc « En préparation »).
            'pending_posts' => $isOwner ? $posts->pendingOf($account) : [],
            'is_following' => $follows->isFollowing($viewer, $account),
            'can_view_network' => $network->canView($account, $viewer),
            'follow_pending' => $viewer instanceof Account && ($f = $follows->findBetween($viewer, $account)) instanceof \App\Core\Domain\Social\Follow && !$f->isAccepted(),
            'story_group' => $stories->groupOf($account, $viewer),
            'highlights' => array_values(array_filter(
                $highlights->forAccount($account),
                static fn (Highlight $h): bool => $stories->highlight($h, $viewer) instanceof \App\Core\Application\Story\StoryGroup,
            )),
            'pending_requests' => $isOwner ? $follows->countPendingRequests($account) : 0,
            'stats' => [
                'posts' => $posts->countForProfile($account, $visible, $viewer),
                'followers' => $follows->countFollowers($account),
                'following' => $follows->countFollowing($account),
            ],
        ];

        return $this->render('profile/show.html.twig', $parameters);
    }

    /**
     * Bloc « En préparation » du profil de l'auteur, rechargé pendant la conversion des vidéos (contrôleur poll).
     */
    #[Route('/@{username}/en-preparation', name: 'app_profile_pending', requirements: ['username' => self::ACCT], methods: ['GET'])]
    public function pending(string $username, Request $request, PostRepository $posts): Response
    {
        $account = $this->account($username);
        $viewer = $this->currentAccount->get();
        if (!$viewer instanceof Account || !$viewer->getId()->equals($account->getId())) {
            throw new NotFoundHttpException();
        }

        return $this->render('profile/_pending.html.twig', [
            'account' => $account,
            'pending_posts' => $posts->pendingOf($account),
            'in_frame' => true,
            // Une publication de moins qu'au départ : elle est prête, la page est rechargée pour la montrer.
            'shown' => max(0, $request->query->getInt('affichees')),
        ]);
    }

    /**
     * Fil des publications d'un profil, ouvert sur l'une d'elles : les plus récentes au-dessus,
     * les plus anciennes en dessous (chargées au fil du défilement).
     */
    #[Route('/@{username}/publications', name: 'app_profile_feed', requirements: ['username' => self::ACCT], methods: ['GET'])]
    public function feed(string $username, Request $request, PostRepository $posts, PostAccess $access, LikeRepository $likes, CommentPreviews $commentPreviews): Response
    {
        $account = $this->account($username);
        $viewer = $this->currentAccount->get();
        $visible = $access->visibleFor($account, $viewer);
        $common = ['account' => $account, 'route' => 'app_profile_feed', 'route_params' => ['username' => $account->getAcct()]];

        // Suite du fil (publications plus anciennes) : chargée au défilement en flux Turbo ; sans JavaScript, page
        // complète qui commence à la suite.
        if ($request->query->has('apres')) {
            $page = $posts->profileTimeline($account, $visible, Cursor::fromString($request->query->getString('apres')), self::FEED_PAGE_SIZE, $viewer);
            $commentPreviews->prime($page->posts);
            $parameters = $common + ['page' => $page, 'liked' => $likes->likedAmong($viewer, $page->posts)];

            return NextPage::requested($request)
                ? $this->render('feed/_page.stream.html.twig', $parameters)
                : $this->render('profile/feed.html.twig', $parameters + ['post' => null, 'newer' => [], 'has_more_newer' => false]);
        }

        $post = $posts->findByPublicId($request->query->getString('depuis'));
        if (!$post instanceof \App\Core\Domain\Post\Post || !$post->getAccount()->getId()->equals($account->getId()) || !$access->canView($post, $viewer)) {
            return $this->redirectToRoute('app_profile', ['username' => $account->getAcct()]);
        }

        $newer = $posts->profileNewerThan($account, $visible, Cursor::after($post), self::FEED_NEWER + 1, $viewer);
        $hasMoreNewer = \count($newer) > self::FEED_NEWER;
        if ($hasMoreNewer) {
            array_shift($newer);
        }
        $older = $posts->profileTimeline($account, $visible, Cursor::after($post), self::FEED_PAGE_SIZE, $viewer);
        $commentPreviews->prime([...$newer, $post, ...$older->posts]);

        return $this->render('profile/feed.html.twig', $common + [
            'post' => $post,
            'newer' => $newer,
            'has_more_newer' => $hasMoreNewer,
            'page' => $older,
            'liked' => $likes->likedAmong($viewer, [...$newer, $post, ...$older->posts]),
        ]);
    }

    /**
     * Archives de la titulaire ou du titulaire du profil : publications archivées, visibles par elle ou lui seul.
     */
    #[Route('/@{username}/archives', name: 'app_profile_archives', requirements: ['username' => self::ACCT], methods: ['GET'])]
    public function archives(string $username, Request $request, PostRepository $posts): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        $account = $this->account($username);
        if (!$account->getId()->equals($this->currentAccount->require()->getId())) {
            throw new NotFoundHttpException('Ces archives ne sont visibles que par leur titulaire.');
        }

        $parameters = [
            'account' => $account,
            'is_owner' => true,
            'page' => $posts->archiveOf($account, Cursor::fromString($request->query->getString('apres') ?: null), self::PAGE_SIZE),
            'archived_count' => $posts->countArchived($account),
            'grid_route' => 'app_profile_archives',
            'open_post' => true,
        ];

        if (NextPage::requested($request)) {
            return $this->render('profile/_grid_page.stream.html.twig', $parameters);
        }

        return $this->render('profile/archives.html.twig', $parameters);
    }

    #[Route('/@{username}/abonnes', name: 'app_profile_followers', requirements: ['username' => self::ACCT], methods: ['GET'])]
    public function followers(string $username, Request $request, FollowRepository $follows, NetworkAccess $network): Response
    {
        return $this->network($username, $request, $follows, $network, 'followers');
    }

    #[Route('/@{username}/abonnements', name: 'app_profile_following', requirements: ['username' => self::ACCT], methods: ['GET'])]
    public function following(string $username, Request $request, FollowRepository $follows, NetworkAccess $network): Response
    {
        return $this->network($username, $request, $follows, $network, 'following');
    }

    /**
     * Retrait d'un abonné : {username} est l'abonné, retiré des abonnés du compte connecté.
     */
    #[Route('/@{username}/retirer-des-abonnes', name: 'app_profile_remove_follower', requirements: ['username' => self::ACCT], methods: ['POST'])]
    public function removeFollower(string $username, Request $request, SocialActions $social): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        $follower = $this->account($username);

        // Le refus d'une demande d'abonnement passe aussi par ici (jeton propre aux demandes).
        if (!$this->isCsrfTokenValid('remove-follower-'.$follower->getAcct(), $request->request->getString('_token'))
            && !$this->isCsrfTokenValid('follow-request-'.$follower->getAcct(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide. Rechargez la page et réessayez.');
        }
        $viewer = $this->currentAccount->require();
        $social->removeFollower($viewer, $follower);

        return $this->redirect($request->headers->get('referer') ?? $this->generateUrl('app_profile_followers', ['username' => $viewer->getAcct()]), Response::HTTP_SEE_OTHER);
    }

    /**
     * Demandes d'abonnement à valider par le compte connecté (réglage « Valider moi-même les demandes d'abonnement »).
     */
    #[Route('/abonnes/demandes', name: 'app_follow_requests', methods: ['GET'])]
    public function followRequests(FollowRepository $follows): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        $account = $this->currentAccount->require();

        return $this->render('profile/follow_requests.html.twig', [
            'account' => $account,
            'requests' => $follows->pendingRequestsOf($account),
            'stats' => [
                'followers' => $follows->countFollowers($account),
                'following' => $follows->countFollowing($account),
                'requests' => $follows->countPendingRequests($account),
            ],
        ]);
    }

    /**
     * Accepte la demande d'abonnement de ce compte (la refuser : app_profile_remove_follower).
     */
    #[Route('/@{username}/accepter-l-abonnement', name: 'app_follow_request_accept', requirements: ['username' => self::ACCT], methods: ['POST'])]
    public function acceptFollower(string $username, Request $request, SocialActions $social): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        $follower = $this->account($username);
        if (!$this->isCsrfTokenValid('follow-request-'.$follower->getAcct(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide. Rechargez la page et réessayez.');
        }
        if ($social->acceptFollower($this->currentAccount->require(), $follower)) {
            $this->addFlash('success', \sprintf('%s fait maintenant partie de vos abonnés.', $follower->getDisplayName()));
        }

        return $this->redirectToRoute('app_follow_requests', status: Response::HTTP_SEE_OTHER);
    }

    /**
     * @param 'followers'|'following' $list
     */
    private function network(string $username, Request $request, FollowRepository $follows, NetworkAccess $network, string $list): Response
    {
        $account = $this->account($username);
        $viewer = $this->currentAccount->get();
        $after = $request->query->getString('apres') ?: null;
        $allowed = $network->canView($account, $viewer);
        $page = match (true) {
            !$allowed => new \App\Core\Application\Social\FollowPage([], null),
            'followers' === $list => $follows->followersPage($account, $after, self::NETWORK_PAGE_SIZE),
            default => $follows->followingPage($account, $after, self::NETWORK_PAGE_SIZE),
        };

        $parameters = [
            'account' => $account,
            'list' => $list,
            'route' => 'followers' === $list ? 'app_profile_followers' : 'app_profile_following',
            'page' => $page,
            'allowed' => $allowed,
            'is_owner' => $isOwner = $viewer instanceof Account && $viewer->getId()->equals($account->getId()),
            'stats' => [
                'followers' => $follows->countFollowers($account),
                'following' => $follows->countFollowing($account),
                'requests' => $isOwner ? $follows->countPendingRequests($account) : 0,
            ],
        ];

        if (null !== $after) {
            return $this->render('profile/_network_page.html.twig', $parameters);
        }

        return $this->render('profile/network.html.twig', $parameters);
    }

    #[Route('/@{username}/suivre', name: 'app_profile_follow', requirements: ['username' => self::ACCT], methods: ['POST'])]
    public function follow(string $username, Request $request, SocialActions $social): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        return $this->changeFollow($username, $request, $social, true);
    }

    #[Route('/@{username}/ne-plus-suivre', name: 'app_profile_unfollow', requirements: ['username' => self::ACCT], methods: ['POST'])]
    public function unfollow(string $username, Request $request, SocialActions $social): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        return $this->changeFollow($username, $request, $social, false);
    }

    private function changeFollow(string $username, Request $request, SocialActions $social, bool $follow): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        $account = $this->account($username);
        if (!$this->isCsrfTokenValid('follow-'.$account->getAcct(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide. Rechargez la page et réessayez.');
        }

        $viewer = $this->currentAccount->require();
        if (!$viewer->getId()->equals($account->getId())) {
            try {
                $follow ? $social->follow($viewer, $account) : $social->unfollow($viewer, $account);
            } catch (PilotedActionFailed $e) {
                // Compte Mastodon : son serveur a refusé, rien n'a changé.
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->redirect($request->headers->get('referer') ?? $this->generateUrl('app_profile', ['username' => $username]), Response::HTTP_SEE_OTHER);
    }

    private function account(string $acct, bool $resolve = false): Account
    {
        if (!str_contains($acct, '@')) {
            return $this->accounts->findLocal($acct) ?? throw new NotFoundHttpException('Ce compte n’existe pas.');
        }

        [$username, $domain] = explode('@', $acct, 2);
        $account = $this->accounts->findOneBy(['username' => mb_strtolower($username), 'domain' => mb_strtolower($domain)]);
        if (null === $account && $resolve) {
            try {
                $account = $this->remote->byHandle($username, $domain);
            } catch (FederationException) {
                $account = null;
            }
        }

        return $account ?? throw new NotFoundHttpException(\sprintf('Le compte @%s est introuvable.', $acct));
    }
}
