<?php

declare(strict_types=1);

namespace App\Tests\Web;

use App\Core\Application\Social\AudienceGroups;
use App\Core\Application\Social\SocialActions;
use App\Core\Domain\Account\Account;
use App\Core\Domain\Account\User;
use App\Core\Domain\Post\Post;
use App\Core\Domain\Post\Visibility;
use App\Core\Domain\Social\AudienceGroup;
use App\Core\Infrastructure\Doctrine\AudienceGroupRepository;
use App\Core\Infrastructure\Doctrine\PostRepository;
use App\Media\Application\PostComposer;
use App\Media\Application\StoryComposer;
use App\Tests\AppWebTestCase;

/**
 * Groupes d'abonnés (Famille, Amis…) et publications ou stories réservées à des groupes.
 */
final class AudienceGroupTest extends AppWebTestCase
{
    private User $camille;
    private User $hugo;
    private User $lina;

    protected function setUp(): void
    {
        parent::setUp();
        $this->camille = $this->register('camille');
        $this->hugo = $this->register('hugo');
        $this->lina = $this->register('lina');
        // Hugo et Lina suivent Camille ; seul Hugo sera dans « Famille ».
        $social = self::getContainer()->get(SocialActions::class);
        $social->follow($this->hugo->getAccount(), $this->camille->getAccount());
        $social->follow($this->lina->getAccount(), $this->camille->getAccount());
    }

    public function testGroupsAreManagedFromTheSettingsAmongFollowers(): void
    {
        $outsider = $this->register('zoe');
        $this->login($this->camille);
        $crawler = $this->client->request('GET', '/reglages/groupes');
        self::assertSelectorExists('.ptabs a[href="/reglages/groupes"].on');
        self::assertSelectorTextContains('main', 'Aucun groupe');

        $this->client->submit($crawler->selectButton('Créer le groupe')->form(['audience_group[name]' => 'Famille']));
        self::assertResponseRedirects();
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.flash.success', 'Groupe « Famille » créé');
        // Seuls les abonnés sont proposés.
        self::assertSelectorTextContains('.member-choices', '@hugo');
        self::assertSelectorTextContains('.member-choices', '@lina');
        self::assertSelectorTextNotContains('.member-choices', '@zoe');

        $form = $crawler->selectButton('Enregistrer')->form();
        /** @var array{audience_group: array<string, mixed>} $values */
        $values = $form->getPhpValues();
        $values['audience_group']['members'] = [$this->hugo->getAccount()->getId()->toRfc4122()];
        $this->client->request('POST', $form->getUri(), $values);
        self::assertResponseRedirects('/reglages/groupes');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.group-list', 'Famille');
        self::assertSelectorTextContains('.group-list', '1 membre');

        $group = $this->group('Famille');
        self::assertSame(['hugo'], array_map(static fn (Account $m): string => $m->getUsername(), $group->getMembers()));

        // Un compte non abonné glissé dans la requête est ignoré.
        self::getContainer()->get(AudienceGroups::class)->setMembers($group, [$outsider->getAccount()->getId()->toRfc4122(), $this->hugo->getAccount()->getId()->toRfc4122()]);
        self::assertSame(['hugo'], array_map(static fn (Account $m): string => $m->getUsername(), $this->group('Famille')->getMembers()));

        // Un autre compte n'a pas accès au groupe.
        $this->login($this->hugo);
        $this->client->request('GET', '/reglages/groupes/'.$group->getPublicId());
        self::assertResponseStatusCodeSame(404);
    }

    public function testAGroupPostIsSeenOnlyByFollowingMembers(): void
    {
        $family = $this->familyWithHugo();
        $post = $this->publish('Anniversaire de mamie', [$family]);
        self::assertSame(Visibility::Groups, $post->getVisibility());
        $page = '/p/'.$post->getPublicId();

        // Hugo, membre abonné : fil d'accueil, profil, page.
        $this->login($this->hugo);
        $this->client->request('GET', '/');
        self::assertSelectorTextContains('main', 'Anniversaire de mamie');
        self::assertSelectorTextContains('.chip.imp', 'Groupe');
        $this->client->request('GET', '/@camille');
        self::assertSelectorExists(\sprintf('a.gt[href*="depuis=%s"]', $post->getPublicId()), 'Dans la grille du profil.');
        $this->client->request('GET', $page);
        self::assertResponseIsSuccessful();

        // Lina, abonnée mais pas membre : rien.
        $this->login($this->lina);
        $this->client->request('GET', '/');
        self::assertSelectorTextNotContains('main', 'Anniversaire de mamie');
        $this->client->request('GET', '/@camille');
        self::assertSelectorNotExists(\sprintf('a.gt[href*="depuis=%s"]', $post->getPublicId()));
        $this->client->request('GET', $page);
        self::assertResponseStatusCodeSame(404);

        // Visiteur, fil local : rien.
        $this->client->request('GET', '/deconnexion');
        $this->client->request('GET', $page);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/local');
        self::assertSelectorTextNotContains('main', 'Anniversaire de mamie');

        // L'autrice voit les noms des groupes.
        $this->login($this->camille);
        $this->client->request('GET', $page);
        self::assertSelectorTextContains('.chip.imp', 'Famille');

        // Hugo se désabonne : il reste dans le groupe mais n'y a plus accès.
        self::getContainer()->get(SocialActions::class)->unfollow($this->hugo->getAccount(), $this->camille->getAccount());
        $this->login($this->hugo);
        $this->client->request('GET', $page);
        self::assertResponseStatusCodeSame(404);
        $this->login($this->camille);
        $this->client->request('GET', '/reglages/groupes/'.$family->getPublicId());
        self::assertSelectorTextContains('.member-choices', 'non abonné');
    }

    public function testPublishingFromTheFormRequiresAtLeastOneGroup(): void
    {
        $family = $this->familyWithHugo();
        $this->login($this->camille);
        $crawler = $this->client->request('GET', '/publier');
        self::assertSelectorExists('input[name="compose_post[visibility]"][value="groups"]:not([disabled])');
        self::assertSelectorExists(\sprintf('.audience-groups input[value="%s"]', $family->getPublicId()));

        /** @var array{compose_post: array<string, mixed>} $values */
        $values = $crawler->selectButton('Publier')->form()->getPhpValues();
        $values['compose_post']['visibility'] = 'groups';
        $this->client->request('POST', '/publier', $values, ['compose_post' => ['media' => [$this->jpeg()]]]);
        self::assertResponseIsUnprocessable();
        self::assertSelectorTextContains('.audience-groups', 'Choisissez au moins un groupe.');

        $values['compose_post']['audienceGroups'] = [$family->getPublicId()];
        $this->client->request('POST', '/publier', $values, ['compose_post' => ['media' => [$this->jpeg()]]]);
        self::assertResponseRedirects();
        $post = self::getContainer()->get(PostRepository::class)->findAll()[0];
        self::assertSame(Visibility::Groups, $post->getVisibility());
        self::assertSame(['Famille'], array_map(static fn (AudienceGroup $g): string => $g->getName(), $post->getAudienceGroups()));

        // Modification : de « Famille » à « Abonnés ».
        $crawler = $this->client->request('GET', '/p/'.$post->getPublicId().'/modifier');
        self::assertSelectorExists(\sprintf('.audience-groups input[value="%s"][checked]', $family->getPublicId()));
        $this->client->submit($crawler->selectButton('Enregistrer les modifications')->form(['edit_post[visibility]' => 'followers']));
        self::assertResponseRedirects();
        self::getContainer()->get('doctrine')->getManager()->clear();
        $post = self::getContainer()->get(PostRepository::class)->findAll()[0];
        self::assertSame(Visibility::Followers, $post->getVisibility());
        self::assertSame([], $post->getAudienceGroups());
    }

    public function testWithoutGroupsTheChoiceIsDisabledWithALinkToCreateOne(): void
    {
        $this->login($this->camille);
        $this->client->request('GET', '/publier');
        self::assertSelectorExists('input[name="compose_post[visibility]"][value="groups"][disabled]');
        self::assertSelectorExists('.audience a[href="/reglages/groupes"]');
    }

    public function testAGroupStoryIsInTheBarOfMembersOnly(): void
    {
        $family = $this->familyWithHugo();
        self::getContainer()->get(StoryComposer::class)->publish($this->camille->getAccount(), [$this->jpeg(1080, 1920)], 'Pique-nique', Visibility::Groups, null, [$family]);

        $this->login($this->hugo);
        $this->client->request('GET', '/');
        self::assertSelectorExists('.stories a[href="/stories?depuis=camille"]');
        $this->login($this->lina);
        $this->client->request('GET', '/');
        self::assertSelectorNotExists('.stories a[href="/stories?depuis=camille"]');
        $this->client->request('GET', '/@camille/stories');
        self::assertResponseStatusCodeSame(404);
    }

    public function testDeletingAGroupMakesWhatOnlyItCouldSeePrivate(): void
    {
        $family = $this->familyWithHugo();
        $friends = self::getContainer()->get(AudienceGroups::class)->create($this->camille->getAccount(), 'Amis');
        $onlyFamily = $this->publish('Seulement la famille', [$family]);
        $both = $this->publish('Famille et amis', [$family, $friends]);

        $this->login($this->camille);
        $crawler = $this->client->request('GET', '/reglages/groupes/'.$family->getPublicId());
        $this->client->submit($crawler->selectButton('Supprimer')->form());
        self::assertResponseRedirects('/reglages/groupes');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash.success', 'est devenu privé');

        self::getContainer()->get('doctrine')->getManager()->clear();
        $posts = self::getContainer()->get(PostRepository::class);
        self::assertSame(Visibility::Private, $posts->findByPublicId($onlyFamily->getPublicId())?->getVisibility());
        $remaining = $posts->findByPublicId($both->getPublicId());
        self::assertSame(Visibility::Groups, $remaining?->getVisibility());
        self::assertSame(['Amis'], array_map(static fn (AudienceGroup $g): string => $g->getName(), $remaining->getAudienceGroups()));
    }

    private function familyWithHugo(): AudienceGroup
    {
        $groups = self::getContainer()->get(AudienceGroups::class);
        $family = $groups->create($this->camille->getAccount(), 'Famille');
        $groups->setMembers($family, [$this->hugo->getAccount()->getId()->toRfc4122()]);

        return $family;
    }

    /**
     * @param list<AudienceGroup> $groups
     */
    private function publish(string $caption, array $groups): Post
    {
        return self::getContainer()->get(PostComposer::class)->publish($this->camille->getAccount(), $caption, [['file' => $this->jpeg(), 'alt' => null]], Visibility::Groups, audienceGroups: $groups);
    }

    private function group(string $name): AudienceGroup
    {
        self::getContainer()->get('doctrine')->getManager()->clear();
        $group = self::getContainer()->get(AudienceGroupRepository::class)->findOneBy(['name' => $name]);
        self::assertInstanceOf(AudienceGroup::class, $group);

        return $group;
    }
}
