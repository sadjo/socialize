<?php

declare(strict_types=1);

namespace App\Web\Controller;

use App\Core\Application\Feed\Cursor;
use App\Core\Application\Post\PostAccess;
use App\Core\Application\Social\SocialActions;
use App\Core\Application\Story\StoryFeed;
use App\Core\Domain\Account\Account;
use App\Core\Domain\Story\Highlight;
use App\Core\Infrastructure\Doctrine\AccountRepository;
use App\Core\Infrastructure\Doctrine\FollowRepository;
use App\Core\Infrastructure\Doctrine\HighlightRepository;
use App\Core\Infrastructure\Doctrine\PostRepository;
use App\Federation\Application\RemoteAccounts;
use App\Federation\Infrastructure\FederationException;
use App\Web\Security\CurrentAccount;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class ProfileController extends AbstractController
{
    private const int PAGE_SIZE = 24;
    /** « camille » ou « leo@pixelfed.social ». */
    private const string ACCT = '[A-Za-z0-9_](?:[A-Za-z0-9_.\-]{0,98}[A-Za-z0-9_])?(?:@[a-z0-9\-]+(?:\.[a-z0-9\-]+)+)?';

    public function __construct(
        private readonly AccountRepository $accounts,
        private readonly CurrentAccount $currentAccount,
        private readonly RemoteAccounts $remote,
    ) {
    }

    #[Route('/@{username}', name: 'app_profile', requirements: ['username' => self::ACCT], methods: ['GET'])]
    public function show(string $username, Request $request, PostRepository $posts, FollowRepository $follows, PostAccess $access, StoryFeed $stories, HighlightRepository $highlights): Response
    {
        $account = $this->account($username, resolve: true);
        $viewer = $this->currentAccount->get();
        $visible = $access->visibleFor($account, $viewer);
        $page = $posts->profileTimeline($account, $visible, Cursor::fromString($request->query->getString('apres') ?: null), self::PAGE_SIZE);

        $parameters = [
            'account' => $account,
            'page' => $page,
            'is_owner' => $viewer instanceof Account && $viewer->getId()->equals($account->getId()),
            'is_following' => $follows->isFollowing($viewer, $account),
            'follow_pending' => $viewer instanceof Account && ($f = $follows->findBetween($viewer, $account)) instanceof \App\Core\Domain\Social\Follow && !$f->isAccepted(),
            'story_group' => $stories->groupOf($account, $viewer),
            'highlights' => array_values(array_filter(
                $highlights->forAccount($account),
                static fn (Highlight $h): bool => $stories->highlight($h, $viewer) instanceof \App\Core\Application\Story\StoryGroup,
            )),
            'stats' => [
                'posts' => $posts->countForProfile($account, $visible),
                'followers' => $follows->countFollowers($account),
                'following' => $follows->countFollowing($account),
            ],
        ];

        if ($request->query->has('apres')) {
            return $this->render('profile/_grid_page.html.twig', $parameters);
        }

        return $this->render('profile/show.html.twig', $parameters);
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
            $follow ? $social->follow($viewer, $account) : $social->unfollow($viewer, $account);
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
