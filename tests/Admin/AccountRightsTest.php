<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Core\Domain\Account\AccountRight;
use App\Core\Domain\Account\User;
use App\Core\Infrastructure\Doctrine\UserRepository;
use App\Tests\AppWebTestCase;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;

/**
 * Seuls les comptes autorisés par l'administration publient (publications, stories, imports, Immich) ;
 * un administrateur le peut toujours, un nouveau compte ne le peut pas tant qu'on ne l'y a pas autorisé.
 */
final class AccountRightsTest extends AppWebTestCase
{
    private const array PUBLISHING_PAGES = ['/publier', '/stories/nouvelle', '/import', '/reglages/immich', '/immich/phototheque'];

    public function testAnAccountWithoutTheRightCanNeitherPublishNorSeeTheButtons(): void
    {
        $this->register('alice');
        $this->login($this->register('hugo', publisher: false));

        foreach (self::PUBLISHING_PAGES as $page) {
            $this->client->request('GET', $page);
            self::assertResponseStatusCodeSame(403, $page);
        }
        $this->client->request('POST', '/publier');
        self::assertResponseStatusCodeSame(403, 'Envoi refusé aussi.');

        $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();
        foreach (['/publier', '/import', '/stories/nouvelle'] as $href) {
            self::assertSelectorNotExists(\sprintf('a[href="%s"]', $href), 'Aucun lien vers '.$href);
        }
        self::assertSelectorExists('nav.side a[href="/reglages"]', 'Le reste du menu est inchangé.');

        $this->client->request('GET', '/reglages');
        self::assertSelectorNotExists('.ptabs a[href="/reglages/immich"]');
        $this->client->request('GET', '/@hugo');
        self::assertSelectorNotExists('a[href="/publier"]');
    }

    public function testTheAdministratorGrantsAndRevokesTheRight(): void
    {
        $admin = $this->register('alice');
        $hugo = $this->register('hugo', publisher: false);
        self::assertTrue($admin->canPublish(), 'Un administrateur peut toujours publier.');

        $this->login($admin);
        $this->client->request('GET', '/publier');
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/admin/comptes');
        self::assertSelectorTextContains('table.accounts', 'Toujours');
        $this->client->submit($this->client->getCrawler()->selectButton('Autoriser')->form());
        self::assertResponseRedirects('/admin/comptes');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash.success', '@hugo peut désormais publier.');
        self::assertTrue($this->reload($hugo)->canPublish());

        $this->login($this->reload($hugo));
        $this->client->request('GET', '/publier');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('nav.side a[href="/import"]', 'L’import n’est plus dans le menu.');
        self::assertSelectorExists('nav.side button[popovertarget="create-menu"]');
        $this->client->request('GET', '/reglages/instagram');
        self::assertSelectorExists('#import a[href="/import"]', 'Il se lance depuis Réglages › Instagram.');
        self::assertSelectorExists('#create-menu a[href="/publier"]');

        $this->login($this->reload($admin));
        $this->client->request('GET', '/admin/comptes');
        $this->client->submit($this->client->getCrawler()->selectButton('Autorisé · Retirer')->form());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash.success', '@hugo ne peut plus publier.');
        self::assertFalse($this->reload($hugo)->canPublish());

        $this->login($this->reload($hugo));
        $this->client->request('GET', '/publier');
        self::assertResponseStatusCodeSame(403);
    }

    public function testOnlyTheAdministratorChangesTheRightWithAValidToken(): void
    {
        $admin = $this->register('alice');
        $hugo = $this->register('hugo', publisher: false);
        $url = '/admin/comptes/'.$hugo->getId()->toRfc4122().'/droits/publication';

        $this->login($this->register('lina'));
        $this->client->request('POST', $url, ['allow' => '1']);
        self::assertResponseStatusCodeSame(403);

        $this->login($admin);
        $this->client->request('POST', $url, ['allow' => '1', '_token' => 'faux']);
        self::assertResponseStatusCodeSame(403);
        self::assertFalse($this->reload($hugo)->canPublish());
    }

    public function testNewAccountsCannotPublishUnlessTheAdministratorTicksTheBox(): void
    {
        $this->login($this->register('alice'));

        foreach (['lina' => false, 'papi' => true] as $username => $allowed) {
            // papi : publication et import cochés ; lina : rien.
            $crawler = $this->client->request('GET', '/admin/comptes');
            $form = $crawler->selectButton('Créer le compte')->form([
                'account[email]' => $username.'@exemple.fr',
                'account[username]' => $username,
                'account[password][first]' => 'mot-de-passe-solide',
                'account[password][second]' => 'mot-de-passe-solide',
            ]);
            if ($allowed) {
                foreach (['account[publisher]', 'account[instagramImport]'] as $name) {
                    $box = $form[$name];
                    self::assertInstanceOf(ChoiceFormField::class, $box);
                    $box->tick();
                }
            }
            $this->client->submit($form);
            self::assertResponseRedirects('/admin/comptes');

            $user = self::getContainer()->get(UserRepository::class)->findOneByEmail($username.'@exemple.fr');
            self::assertInstanceOf(User::class, $user);
            self::assertSame($allowed, $user->canPublish(), $username);
            self::assertSame($allowed, $user->can(AccountRight::InstagramImport), $username);
        }
    }

    public function testTheInstagramImportNeedsItsOwnRightOnTopOfPublishing(): void
    {
        $admin = $this->register('alice');
        $hugo = $this->register('hugo', importer: false);
        self::assertTrue($admin->can(AccountRight::InstagramImport), 'Un administrateur peut toujours importer.');

        // Publier sans importer : création possible, import refusé et absent du menu.
        $this->login($hugo);
        $this->client->request('GET', '/publier');
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/import');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/');
        self::assertSelectorExists('nav.side button[popovertarget="create-menu"]');
        self::assertSelectorExists('#create-menu a[href="/publier"]');
        self::assertSelectorNotExists('a[href="/import"]');
        $this->client->request('GET', '/reglages/instagram');
        self::assertResponseIsSuccessful('L’onglet Instagram reste ouvert au publieur (publier aussi sur Instagram).');
        self::assertSelectorNotExists('#import', 'Sans le droit, pas de section d’import.');

        // L'administrateur l'accorde dans la colonne « Import Instagram ».
        $this->login($admin);
        $crawler = $this->client->request('GET', '/admin/comptes');
        self::assertSelectorTextContains('table.accounts .rights', 'Import Instagram');
        $importForm = $crawler->filter(\sprintf('form[action="/admin/comptes/%s/droits/import-instagram"]', $hugo->getId()->toRfc4122()));
        self::assertCount(1, $importForm);
        $this->client->submit($importForm->selectButton('Autoriser')->form());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash.success', '@hugo peut désormais importer son archive Instagram.');
        self::assertTrue($this->reload($hugo)->can(AccountRight::InstagramImport));

        $this->login($this->reload($hugo));
        $this->client->request('GET', '/reglages/instagram');
        self::assertSelectorTextContains('#import h2', 'Importer mon archive Instagram');
        $this->client->click($this->client->getCrawler()->filter('#import a[href="/import"]')->link());
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('nav.side a.nav.on[href="/reglages"]', 'Pendant l’import, Réglages reste allumé dans le menu.');
        self::assertSelectorExists('.page-h a[href="/reglages/instagram"]', 'Retour aux réglages.');

        // Sans le droit de publier, le droit d'import ne suffit plus (et l'administration le signale).
        $user = $this->reload($hugo);
        $user->revoke(AccountRight::Publish);
        self::getContainer()->get('doctrine.orm.entity_manager')->flush();
        self::assertFalse($user->can(AccountRight::InstagramImport));
        $this->login($user);
        $this->client->request('GET', '/import');
        self::assertResponseStatusCodeSame(403);

        $this->login($this->reload($admin));
        $this->client->request('GET', '/admin/comptes');
        self::assertSelectorTextContains('table.accounts', 'Nécessite la publication');
    }

    public function testTheImportBoxNeedsThePublishBoxOnTheNewAccountForm(): void
    {
        $this->login($this->register('alice'));
        $crawler = $this->client->request('GET', '/admin/comptes');
        self::assertSelectorExists('[data-controller="requires"] input[name="account[publisher]"][data-requires-target="source"]');
        self::assertSelectorExists('[data-controller="requires"] input[name="account[instagramImport]"][data-requires-target="dependent"]');

        // Sans JavaScript, la case reste cochable seule : le serveur refuse.
        $form = $crawler->selectButton('Créer le compte')->form([
            'account[email]' => 'lina@exemple.fr',
            'account[username]' => 'lina',
            'account[password][first]' => 'mot-de-passe-solide',
            'account[password][second]' => 'mot-de-passe-solide',
        ]);
        $box = $form['account[instagramImport]'];
        self::assertInstanceOf(ChoiceFormField::class, $box);
        $box->tick();
        $this->client->submit($form);
        self::assertResponseIsUnprocessable();
        self::assertSelectorTextContains('main', 'L’import Instagram suppose le droit de publier');
        self::assertNull(self::getContainer()->get(UserRepository::class)->findOneByEmail('lina@exemple.fr'));
    }

    private function reload(User $user): User
    {
        $fresh = self::getContainer()->get(UserRepository::class)->find($user->getId());
        self::assertInstanceOf(User::class, $fresh);

        return $fresh;
    }
}
