<?php

declare(strict_types=1);

namespace App\Web\Controller;

use App\Core\Application\Social\AudienceGroups;
use App\Core\Domain\Account\Account;
use App\Core\Domain\Social\AudienceGroup;
use App\Core\Infrastructure\Doctrine\AudienceGroupRepository;
use App\Core\Infrastructure\Doctrine\FollowRepository;
use App\Web\Form\AudienceGroupType;
use App\Web\Security\CurrentAccount;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Réglages › Groupes : groupes d'abonnés (Famille, Amis…) auxquels réserver des publications et des stories.
 */
#[IsGranted('ROLE_USER')]
final class AudienceGroupController extends AbstractController
{
    public function __construct(
        private readonly AudienceGroups $groups,
        private readonly AudienceGroupRepository $repository,
        private readonly FollowRepository $follows,
        private readonly CurrentAccount $currentAccount,
    ) {
    }

    #[Route('/reglages/groupes', name: 'app_settings_groups', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $account = $this->currentAccount->require();
        $form = $this->createForm(AudienceGroupType::class);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{name: string} $data */
            $data = $form->getData();
            try {
                $group = $this->groups->create($account, $data['name']);
                $this->addFlash('success', \sprintf('Groupe « %s » créé : choisissez maintenant ses membres.', $group->getName()));

                return $this->redirectToRoute('app_settings_group', ['id' => $group->getPublicId()], Response::HTTP_SEE_OTHER);
            } catch (\DomainException $e) {
                $form->addError(new FormError($e->getMessage()));
            }
        }

        return $this->render('settings/groups.html.twig', [
            'account' => $account,
            'form' => $form,
            'groups' => $this->groups->of($account),
            'followers' => \count($this->follows->followersOf($account)),
        ]);
    }

    #[Route('/reglages/groupes/{id}', name: 'app_settings_group', requirements: ['id' => Requirement::UID_BASE58], methods: ['GET', 'POST'])]
    public function edit(string $id, Request $request): Response
    {
        $account = $this->currentAccount->require();
        $group = $this->owned($account, $id);
        $followers = $this->follows->followersOf($account);
        $candidates = [];
        foreach ([...$followers, ...$group->getMembers()] as $candidate) {
            $candidates[$candidate->getId()->toRfc4122()] = $candidate;
        }
        uasort($candidates, static fn (Account $a, Account $b): int => strcasecmp($a->getAcct(), $b->getAcct()));

        $form = $this->createForm(AudienceGroupType::class, [
            'name' => $group->getName(),
            'members' => array_map(static fn (Account $m): string => $m->getId()->toRfc4122(), $group->getMembers()),
        ], ['accounts' => array_values($candidates)]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{name: string, members: list<string>} $data */
            $data = $form->getData();
            $this->groups->rename($group, $data['name']);
            $this->groups->setMembers($group, $data['members']);
            $this->addFlash('success', \sprintf('Groupe « %s » enregistré (%d membre%s).', $group->getName(), \count($group->getMembers()), \count($group->getMembers()) > 1 ? 's' : ''));

            return $this->redirectToRoute('app_settings_groups', status: Response::HTTP_SEE_OTHER);
        }

        $following = array_fill_keys(array_map(static fn (Account $f): string => $f->getId()->toRfc4122(), $followers), true);

        return $this->render('settings/group.html.twig', [
            'account' => $account,
            'group' => $group,
            'form' => $form,
            'candidates' => $candidates,
            'following' => $following,
        ]);
    }

    #[Route('/reglages/groupes/{id}/supprimer', name: 'app_settings_group_delete', requirements: ['id' => Requirement::UID_BASE58], methods: ['POST'])]
    public function delete(string $id, Request $request): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        $group = $this->owned($this->currentAccount->require(), $id);
        if (!$this->isCsrfTokenValid('group-delete-'.$id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide. Rechargez la page et réessayez.');
        }
        $name = $group->getName();
        $this->groups->delete($group);
        $this->addFlash('success', \sprintf('Groupe « %s » supprimé. Ce qui n’était réservé qu’à lui est devenu privé.', $name));

        return $this->redirectToRoute('app_settings_groups', status: Response::HTTP_SEE_OTHER);
    }

    private function owned(Account $account, string $id): AudienceGroup
    {
        return $this->repository->findOwned($account, $id) ?? throw $this->createNotFoundException('Groupe introuvable.');
    }
}
