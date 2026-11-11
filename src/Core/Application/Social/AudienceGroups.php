<?php

declare(strict_types=1);

namespace App\Core\Application\Social;

use App\Core\Domain\Account\Account;
use App\Core\Domain\Event\PostUpdated;
use App\Core\Domain\Post\Post;
use App\Core\Domain\Post\Visibility;
use App\Core\Domain\Social\AudienceGroup;
use App\Core\Domain\Story\Story;
use App\Core\Infrastructure\Doctrine\AudienceGroupRepository;
use App\Core\Infrastructure\Doctrine\FollowRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Bridge\Doctrine\Types\UuidType;

/**
 * Groupes d'abonnés d'un compte (Famille, Amis…) : création, membres, suppression.
 */
final readonly class AudienceGroups
{
    public function __construct(
        private AudienceGroupRepository $groups,
        private FollowRepository $follows,
        private EntityManagerInterface $em,
        private EventDispatcherInterface $events,
    ) {
    }

    /**
     * @return list<AudienceGroup>
     */
    public function of(Account $owner): array
    {
        return $this->groups->ownedBy($owner);
    }

    /**
     * @throws \DomainException
     */
    public function create(Account $owner, string $name): AudienceGroup
    {
        if (\count($this->groups->ownedBy($owner)) >= AudienceGroup::MAX_PER_ACCOUNT) {
            throw new \DomainException(\sprintf('Vous avez déjà %d groupes : supprimez-en un avant d’en créer un autre.', AudienceGroup::MAX_PER_ACCOUNT));
        }
        $group = new AudienceGroup($owner, $name);
        $this->em->persist($group);
        $this->em->flush();

        return $group;
    }

    public function rename(AudienceGroup $group, string $name): void
    {
        $group->rename($name);
        $this->em->flush();
    }

    /**
     * Membres du groupe, choisis parmi les abonnés (les autres sont ignorés) ; un ancien membre qui s'est désabonné
     * peut rester dans le groupe, sans accès.
     *
     * @param list<string> $memberIds identifiants (RFC 4122) des comptes choisis
     */
    public function setMembers(AudienceGroup $group, array $memberIds): void
    {
        $allowed = [];
        foreach ([...$this->follows->followersOf($group->getOwner()), ...$group->getMembers()] as $account) {
            $allowed[$account->getId()->toRfc4122()] = $account;
        }
        $group->replaceMembers(array_values(array_intersect_key($allowed, array_flip($memberIds))));
        $this->em->flush();
    }

    /**
     * Supprime le groupe. Les publications et stories qui n'étaient réservées qu'à lui deviennent privées ;
     * le fédivers en est informé (ses membres distants ne les voient plus).
     */
    public function delete(AudienceGroup $group): void
    {
        $updated = [];
        foreach ($this->restrictedTo($group, Post::class) as $post) {
            $audience = $post->getAudienceMembers();
            $post->restrictTo(array_values(array_filter($post->getAudienceGroups(), static fn (AudienceGroup $g): bool => $g !== $group)));
            $updated[] = new PostUpdated($post, Visibility::Groups, $audience);
        }
        foreach ($this->restrictedTo($group, Story::class) as $story) {
            $story->restrictTo(array_values(array_filter($story->getAudienceGroups(), static fn (AudienceGroup $g): bool => $g !== $group)));
        }
        $this->em->remove($group);
        $this->em->flush();
        foreach ($updated as $event) {
            $this->events->dispatch($event);
        }
    }

    /**
     * @template T of Post|Story
     *
     * @param class-string<T> $class
     *
     * @return list<T>
     */
    private function restrictedTo(AudienceGroup $group, string $class): array
    {
        /** @var list<T> $found */
        $found = $this->em->createQueryBuilder()
            ->select('x')
            ->from($class, 'x')
            ->where(':group MEMBER OF x.audienceGroups')
            ->setParameter('group', $group->getId(), UuidType::NAME)
            ->getQuery()
            ->getResult();

        return $found;
    }
}
