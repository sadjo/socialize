<?php

declare(strict_types=1);

namespace App\Web\Controller;

use App\Core\Application\Story\StoryAccess;
use App\Core\Application\Story\StoryActions;
use App\Core\Application\Story\StoryFeed;
use App\Core\Application\Story\StoryGroup;
use App\Core\Domain\Account\Account;
use App\Core\Domain\Post\Visibility;
use App\Core\Domain\Story\Highlight;
use App\Core\Domain\Story\Story;
use App\Core\Infrastructure\Doctrine\AccountRepository;
use App\Core\Infrastructure\Doctrine\HighlightRepository;
use App\Core\Infrastructure\Doctrine\StoryRepository;
use App\Integration\Immich\Application\ImmichPublisher;
use App\Media\Application\MediaProcessingException;
use App\Media\Application\StoryComposer;
use App\Web\Form\ComposeStoryType;
use App\Web\Security\CurrentAccount;
use App\Web\Twig\StoryViewerData;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class StoryController extends AbstractController
{
    private const string ID = '[1-9A-HJ-NP-Za-km-z]{22}';
    private const string USERNAME = '[a-z0-9_](?:[a-z0-9_.]{0,28}[a-z0-9_])?';

    public function __construct(
        private readonly StoryRepository $stories,
        private readonly HighlightRepository $highlights,
        private readonly StoryFeed $feed,
        private readonly StoryAccess $access,
        private readonly StoryActions $actions,
        private readonly CurrentAccount $currentAccount,
        private readonly ClockInterface $clock,
        private readonly StoryViewerData $viewerData,
    ) {
    }

    #[Route('/stories/nouvelle', name: 'app_story_new', methods: ['GET', 'POST'])]
    public function new(Request $request, ImmichPublisher $publisher, ComposeSources $sources): Response
    {
        $account = $this->currentAccount->require();
        $form = $this->createForm(ComposeStoryType::class, options: ['highlights' => $this->highlights->forAccount($account)]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{media: list<UploadedFile>, order: ?string, text: ?string, audience: string, highlight: ?string, highlightTitle: ?string} $data */
            $data = $form->getData();
            $highlightTitle = trim((string) $data['highlightTitle']);
            if ('__new__' === $data['highlight'] && '' === $highlightTitle) {
                $form->get('highlightTitle')->addError(new FormError('Donnez un titre à la nouvelle story à la une.'));
            } else {
                try {
                    $created = $publisher->publishStories($account, ComposeSources::order($data['order']), $data['media'], $data['text'], Visibility::from($data['audience']));
                    $this->pinAll($created, $account, $data['highlight'], $highlightTitle);
                    $this->addFlash('success', 1 === \count($created) ? 'Story publiée, visible pendant 24 h.' : \sprintf('%d stories publiées, visibles pendant 24 h.', \count($created)));

                    return $this->redirectToRoute('app_home', status: Response::HTTP_SEE_OTHER);
                } catch (MediaProcessingException $e) {
                    $form->get('media')->addError(new FormError($e->getMessage()));
                }
            }
        }

        return $this->render('story/new.html.twig', ['form' => $form] + $sources->forCurrentAccount());
    }

    /**
     * Visionneuse de la barre du fil : toutes les stories des comptes suivis, à partir d'un compte.
     */
    #[Route('/stories', name: 'app_stories', methods: ['GET'])]
    public function feed(Request $request): Response
    {
        $viewer = $this->currentAccount->require();
        $groups = $this->feed->barFor($viewer);
        if ([] === $groups) {
            return $this->redirectToRoute('app_home');
        }

        $start = 0;
        foreach ($groups as $i => $group) {
            if ($group->account->getUsername() === $request->query->getString('depuis')) {
                $start = $i;
            }
        }

        return $this->viewer($groups, $start, $this->generateUrl('app_home'));
    }

    #[Route('/@{username}/stories', name: 'app_account_stories', requirements: ['username' => self::USERNAME], methods: ['GET'])]
    public function account(string $username, AccountRepository $accounts): Response
    {
        $account = $accounts->findLocal($username) ?? throw new NotFoundHttpException('Ce compte n’existe pas.');
        $group = $this->feed->groupOf($account, $this->currentAccount->get())
            ?? throw new NotFoundHttpException('Ce compte n’a pas de story en cours.');

        return $this->viewer([$group], 0, $this->generateUrl('app_profile', ['username' => $username]));
    }

    #[Route('/@{username}/a-la-une/{id}', name: 'app_highlight', requirements: ['username' => self::USERNAME, 'id' => self::ID], methods: ['GET'])]
    public function highlight(string $username, string $id): Response
    {
        $highlight = $this->highlights->findByPublicId($id);
        if (!$highlight instanceof Highlight || $highlight->getAccount()->getUsername() !== $username) {
            throw new NotFoundHttpException('Cette story à la une n’existe pas.');
        }
        $group = $this->feed->highlight($highlight, $this->currentAccount->get())
            ?? throw new NotFoundHttpException('Cette story à la une ne contient aucune story visible.');

        return $this->viewer([$group], 0, $this->generateUrl('app_profile', ['username' => $username]), $highlight);
    }

    /**
     * Stories archivées (expirées), visibles par leur seul compte : la visionneuse s'ouvre sur celle choisie
     * et passe aux autres dans l'ordre de la page « Mes stories ».
     */
    #[Route('/stories/archives/{id}', name: 'app_story_archive', requirements: ['id' => self::ID], methods: ['GET'])]
    public function archive(string $id): Response
    {
        $account = $this->currentAccount->require();
        $story = $this->stories->findByPublicId($id);
        if (!$story instanceof Story || !$story->isOwnedBy($account)) {
            throw new NotFoundHttpException('Cette story n’existe pas.');
        }
        // Même ordre que la grille des archives (la plus récente d'abord) : « suivante » est la vignette d'après.
        $stories = $this->stories->archiveOf($account, $this->clock->now());
        if (!\in_array($story, $stories, true)) {
            $stories = [$story];
        }

        return $this->render('story/viewer.html.twig', [
            'data' => $this->viewerData->build([new StoryGroup($account, $stories)], 0, $this->generateUrl('app_story_manage'), $account, startStory: $id, label: 'Archives'),
            'title' => 'Archives',
        ]);
    }

    #[Route('/stories/{id}/vue', name: 'app_story_seen', requirements: ['id' => self::ID], methods: ['POST'])]
    public function seen(string $id, Request $request): Response
    {
        $viewer = $this->currentAccount->get();
        $story = $this->visibleStory($id);
        if ($viewer instanceof Account && $this->isCsrfTokenValid('story-view', $request->request->getString('_token'))) {
            $this->actions->markSeen($viewer, $story);
        }

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    #[Route('/stories/mes-stories', name: 'app_story_manage', methods: ['GET'])]
    public function manage(): Response
    {
        $account = $this->currentAccount->require();
        $now = $this->clock->now();

        return $this->render('story/manage.html.twig', [
            'active' => $this->stories->activeOf($account, [Visibility::Public, Visibility::Followers], $now),
            'archive' => $this->stories->archiveOf($account, $now),
            'highlights' => $this->highlights->forAccount($account),
        ]);
    }

    #[Route('/stories/{id}/a-la-une', name: 'app_story_pin', requirements: ['id' => self::ID], methods: ['POST'])]
    public function pin(string $id, Request $request): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        $story = $this->ownStory($id, $request, 'story-pin-'.$id);
        $target = $request->request->getString('highlight');
        try {
            $highlight = $this->actions->pin(
                $story,
                '__new__' === $target ? null : $this->ownHighlight($target, $story->getAccount()),
                $request->request->getString('title'),
            );
            $this->addFlash('success', \sprintf('Story ajoutée à « %s ».', $highlight->getTitle()));
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_story_manage', status: Response::HTTP_SEE_OTHER);
    }

    #[Route('/stories/{id}/retirer-de-la-une', name: 'app_story_unpin', requirements: ['id' => self::ID], methods: ['POST'])]
    public function unpin(string $id, Request $request): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        $this->actions->unpin($this->ownStory($id, $request, 'story-unpin-'.$id));
        $this->addFlash('success', 'Story retirée de la une.');

        return $this->redirectToRoute('app_story_manage', status: Response::HTTP_SEE_OTHER);
    }

    #[Route('/stories/{id}/supprimer', name: 'app_story_delete', requirements: ['id' => self::ID], methods: ['POST'])]
    public function delete(string $id, Request $request, StoryComposer $composer): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        $composer->delete($this->ownStory($id, $request, 'story-delete-'.$id));
        $this->addFlash('success', 'Story supprimée.');

        return $this->redirectToRoute('app_story_manage', status: Response::HTTP_SEE_OTHER);
    }

    #[Route('/a-la-une/{id}/supprimer', name: 'app_highlight_delete', requirements: ['id' => self::ID], methods: ['POST'])]
    public function deleteHighlight(string $id, Request $request): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        $account = $this->currentAccount->require();
        $highlight = $this->ownHighlight($id, $account) ?? throw new NotFoundHttpException();
        if (!$this->isCsrfTokenValid('highlight-delete-'.$id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide. Rechargez la page et réessayez.');
        }
        $this->actions->deleteHighlight($highlight);
        $this->addFlash('success', 'Story à la une supprimée. Ses stories restent dans vos archives.');

        return $this->redirectToRoute('app_story_manage', status: Response::HTTP_SEE_OTHER);
    }

    /**
     * @param list<StoryGroup> $groups
     */
    private function viewer(array $groups, int $start, string $closeUrl, ?Highlight $highlight = null): Response
    {
        return $this->render('story/viewer.html.twig', [
            'data' => $this->viewerData->build($groups, $start, $closeUrl, $this->currentAccount->get(), $highlight),
            'title' => $highlight?->getTitle() ?? 'Stories',
        ]);
    }

    /**
     * @param list<Story> $stories
     */
    private function pinAll(array $stories, Account $account, ?string $target, string $title): void
    {
        if (null === $target || '' === $target) {
            return;
        }
        $highlight = '__new__' === $target ? null : $this->ownHighlight($target, $account);
        foreach ($stories as $story) {
            $highlight = $this->actions->pin($story, $highlight, $title);
        }
    }

    private function visibleStory(string $id): Story
    {
        $story = $this->stories->findByPublicId($id);
        if (!$story instanceof Story || !$this->access->canView($story, $this->currentAccount->get(), $this->clock->now())) {
            throw new NotFoundHttpException('Cette story n’existe pas ou n’est plus visible.');
        }

        return $story;
    }

    private function ownStory(string $id, Request $request, string $tokenId): Story
    {
        $story = $this->stories->findByPublicId($id);
        if (!$story instanceof Story || !$story->isOwnedBy($this->currentAccount->require())) {
            throw new NotFoundHttpException('Cette story n’existe pas.');
        }
        if (!$this->isCsrfTokenValid($tokenId, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide. Rechargez la page et réessayez.');
        }

        return $story;
    }

    private function ownHighlight(string $id, Account $account): ?Highlight
    {
        $highlight = $this->highlights->findByPublicId($id);

        return $highlight instanceof Highlight && $highlight->isOwnedBy($account) ? $highlight : null;
    }
}
