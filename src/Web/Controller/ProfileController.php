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
use App\Web\Security\CurrentAccount;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class ProfileController extends AbstractController
{
    private const int PAGE_SIZE = 24;
    private const string USERNAME = '[a-z0-9_](?:[a-z0-9_.]{0,28}[a-z0-9_])?';

    public function __construct(
        private readonly AccountRepository $accounts,
        private readonly CurrentAccount $currentAccount,
    ) {
    }

    #[Route('/@{username}', name: 'app_profile', requirements: ['username' => self::USERNAME], methods: ['GET'])]
    public function show(string $username, Request $request, PostRepository $posts, FollowRepository $follows, PostAccess $access, StoryFeed $stories, HighlightRepository $highlights): Response
    {
        $account = $this->account($username);
        $viewer = $this->currentAccount->get();
        $visible = $access->visibleFor($account, $viewer);
        $page = $posts->profileTimeline($account, $visible, Cursor::fromString($request->query->getString('apres') ?: null), self::PAGE_SIZE);

        $parameters = [
            'account' => $account,
            'page' => $page,
            'is_owner' => $viewer instanceof Account && $viewer->getId()->equals($account->getId()),
            'is_following' => $follows->isFollowing($viewer, $account),
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

    #[Route('/@{username}/suivre', name: 'app_profile_follow', requirements: ['username' => self::USERNAME], methods: ['POST'])]
    public function follow(string $username, Request $request, SocialActions $social): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        return $this->changeFollow($username, $request, $social, true);
    }

    #[Route('/@{username}/ne-plus-suivre', name: 'app_profile_unfollow', requirements: ['username' => self::USERNAME], methods: ['POST'])]
    public function unfollow(string $username, Request $request, SocialActions $social): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        return $this->changeFollow($username, $request, $social, false);
    }

    private function changeFollow(string $username, Request $request, SocialActions $social, bool $follow): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        $account = $this->account($username);
        if (!$this->isCsrfTokenValid('follow-'.$account->getUsername(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide. Rechargez la page et réessayez.');
        }

        $viewer = $this->currentAccount->require();
        if (!$viewer->getId()->equals($account->getId())) {
            $follow ? $social->follow($viewer, $account) : $social->unfollow($viewer, $account);
        }

        return $this->redirect($request->headers->get('referer') ?? $this->generateUrl('app_profile', ['username' => $username]), Response::HTTP_SEE_OTHER);
    }

    private function account(string $username): Account
    {
        return $this->accounts->findLocal($username) ?? throw new NotFoundHttpException('Ce compte n’existe pas.');
    }
}
