<?php

declare(strict_types=1);

namespace App\Web\Twig;

use App\Core\Application\Story\StoryGroup;
use App\Core\Domain\Account\Account;
use App\Core\Domain\Media\MediaAttachment;
use App\Core\Domain\Story\Highlight;
use App\Media\Application\MediaUrls;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Données JSON de la visionneuse : stories regroupées par compte, prêtes à être lues par Stimulus.
 */
final readonly class StoryViewerData
{
    public function __construct(
        private MediaUrls $urls,
        private UrlGeneratorInterface $router,
        private CsrfTokenManagerInterface $csrf,
        private SocializeExtension $twig,
    ) {
    }

    /**
     * @param list<StoryGroup> $groups
     * @param string|null      $startStory story par laquelle commencer (sinon la première pas encore vue)
     * @param string|null      $label      mention affichée à côté de la date (« Archives »…)
     *
     * @return array<string, mixed>
     */
    public function build(array $groups, int $start, string $closeUrl, ?Account $viewer, ?Highlight $highlight = null, ?string $startStory = null, ?string $label = null): array
    {
        $viewToken = $viewer instanceof Account ? $this->csrf->getToken('story-view')->getValue() : null;

        $data = [];
        foreach ($groups as $group) {
            $account = $group->account;
            $own = $viewer instanceof Account && $viewer->getId()->equals($account->getId());
            $stories = [];
            foreach ($group->stories as $story) {
                $media = $story->getMedia();
                if (null === $media || !$media->isReady()) {
                    continue;
                }
                $stories[] = [
                    'id' => $story->getPublicId(),
                    'type' => $media->isVideo() ? 'video' : 'image',
                    'src' => $this->urls->derivative($media, $media->isVideo() ? MediaAttachment::DERIVATIVE_VIDEO : MediaAttachment::DERIVATIVE_LARGE),
                    'poster' => $media->isVideo() ? $this->urls->derivative($media, MediaAttachment::DERIVATIVE_POSTER) : null,
                    'duration' => $media->isVideo() ? ($media->getDuration() ?? 15.0) : 5.0,
                    'text' => $story->getText(),
                    'ago' => $this->twig->ago($story->getCreatedAt()),
                    'seen' => isset($group->seen[$story->getPublicId()]),
                    'seenUrl' => null !== $viewToken && !$own ? $this->router->generate('app_story_seen', ['id' => $story->getPublicId()]) : null,
                    'views' => $own ? $story->getViewsCount() : null,
                    'audience' => $story->getAudience()->value,
                ];
            }
            if ([] === $stories) {
                continue;
            }
            $data[] = [
                'username' => $account->getUsername(),
                'name' => $account->getDisplayName(),
                'initials' => $account->getInitials(),
                'hue' => $account->getHue(),
                'avatar' => null !== $account->getAvatarPath() ? $this->urls->path($account->getAvatarPath()) : null,
                'profileUrl' => $this->router->generate('app_profile', ['username' => $account->getUsername()]),
                'own' => $own,
                'start' => null !== $startStory && \in_array($startStory, array_column($stories, 'id'), true)
                    ? (int) array_search($startStory, array_column($stories, 'id'), true)
                    : $group->firstUnseenIndex(),
                'stories' => $stories,
            ];
        }

        return [
            'groups' => $data,
            'start' => min($start, max(0, \count($data) - 1)),
            'closeUrl' => $closeUrl,
            'manageUrl' => $viewer instanceof Account ? $this->router->generate('app_story_manage') : null,
            'viewToken' => $viewToken,
            'highlight' => $highlight?->getTitle() ?? $label,
        ];
    }
}
