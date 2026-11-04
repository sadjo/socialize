<?php

declare(strict_types=1);

namespace App\Web\Controller;

use App\Core\Application\Account\RegistrationPolicy;
use App\Core\Application\Feed\Cursor;
use App\Core\Application\Feed\TimelinePage;
use App\Core\Application\Story\StoryFeed;
use App\Core\Infrastructure\Doctrine\AccountRepository;
use App\Core\Infrastructure\Doctrine\LikeRepository;
use App\Core\Infrastructure\Doctrine\PostRepository;
use App\Web\Security\CurrentAccount;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class FeedController extends AbstractController
{
    private const int PAGE_SIZE = 10;

    public function __construct(
        private readonly PostRepository $posts,
        private readonly LikeRepository $likes,
        private readonly CurrentAccount $currentAccount,
    ) {
    }

    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function home(Request $request, AccountRepository $accounts, RegistrationPolicy $registration, StoryFeed $stories): Response
    {
        $viewer = $this->currentAccount->get();
        if (!$viewer instanceof \App\Core\Domain\Account\Account) {
            return $this->renderFeed($request, 'welcome', $this->posts->localTimeline($this->cursor($request), self::PAGE_SIZE), [
                'registration_open' => $registration->isOpen(),
            ]);
        }

        return $this->renderFeed($request, 'home', $this->posts->homeTimeline($viewer, $this->cursor($request), self::PAGE_SIZE), [
            'suggestions' => $accounts->suggestionsFor($viewer),
            'story_groups' => $stories->barFor($viewer),
        ]);
    }

    #[Route('/local', name: 'app_feed_local', methods: ['GET'])]
    public function local(Request $request, AccountRepository $accounts): Response
    {
        $viewer = $this->currentAccount->get();

        return $this->renderFeed($request, 'local', $this->posts->localTimeline($this->cursor($request), self::PAGE_SIZE), [
            'suggestions' => $viewer instanceof \App\Core\Domain\Account\Account ? $accounts->suggestionsFor($viewer) : [],
        ]);
    }

    #[Route('/federe', name: 'app_feed_federated', methods: ['GET'])]
    public function federated(): Response
    {
        return $this->render('feed/federated.html.twig', ['tab' => 'federated']);
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function renderFeed(Request $request, string $tab, TimelinePage $page, array $extra = []): Response
    {
        $parameters = [
            'tab' => $tab,
            'page' => $page,
            'liked' => $this->likes->likedAmong($this->currentAccount->get(), $page->posts),
            'route' => $request->attributes->getString('_route'),
            ...$extra,
        ];

        // Pages suivantes : seulement la liste, chargée dans un cadre Turbo.
        if ($request->query->has('apres')) {
            return $this->render('feed/_page.html.twig', $parameters);
        }

        return $this->render('feed/index.html.twig', $parameters);
    }

    private function cursor(Request $request): ?Cursor
    {
        return Cursor::fromString($request->query->getString('apres') ?: null);
    }
}
