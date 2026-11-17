<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Media\Application\MediaUrls;
use App\Tests\AppWebTestCase;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Bloc « Instance » à côté des fils : titre et texte en Markdown, choisis par l'administrateur, avec images envoyées
 * depuis la barre d'édition et aperçu.
 */
final class InstanceAboutTest extends AppWebTestCase
{
    public function testADefaultBlockIsShownUntilTheAdministratorWritesOne(): void
    {
        $this->client->request('GET', '/local');
        self::assertSelectorTextContains('.fedstat h2', 'Instance localhost');
        self::assertSelectorTextContains('.fedstat .md', 'fédéré avec Mastodon et Pixelfed');
        self::assertSelectorNotExists('.fedstat a[href="/admin/instance"]', 'Pas de lien « Modifier » pour un visiteur.');
    }

    public function testTheAdministratorWritesTheBlockInMarkdown(): void
    {
        $this->loginAsAdmin();
        $this->client->request('GET', '/local');
        self::assertSelectorExists('.fedstat a[href="/admin/instance"]', 'Lien « Modifier » pour l’administrateur.');

        $crawler = $this->client->request('GET', '/admin/instance');
        self::assertSelectorExists('.ptabs a.on[href="/admin/instance"]');
        self::assertSelectorExists('[data-controller="markdown-editor"] textarea[data-markdown-editor-target="input"]');
        self::assertSelectorCount(6, '.md-tools button', 'Gras, italique, lien, puces, liste numérotée, image.');
        self::assertSame('Instance localhost', $crawler->filter('#instance_about_title')->attr('value'));

        $this->client->submitForm('Enregistrer', [
            'instance_about[title]' => 'Bienvenue chez Alice',
            'instance_about[body]' => "Un **réseau photo** de famille.\n\n- [Mastodon](https://mastodon.social/@alice)\n- [Accueil](https://localhost/local)\n\n![Coucher de soleil](/m/instance/soleil.webp)\n\n<script>alert(1)</script> [piège](javascript:alert(1))",
        ]);
        self::assertResponseRedirects('/admin/instance');

        $this->client->request('GET', '/local');
        self::assertSelectorTextContains('.fedstat h2', 'Bienvenue chez Alice');
        self::assertSelectorTextContains('.fedstat .md strong', 'réseau photo');
        self::assertSelectorCount(2, '.fedstat .md ul li');
        self::assertSelectorExists('.fedstat .md a[href="https://mastodon.social/@alice"][target="_blank"][rel~="nofollow"]', 'Lien externe : nouvel onglet, nofollow.');
        self::assertSelectorExists('.fedstat .md img[src="/m/instance/soleil.webp"][alt="Coucher de soleil"][loading="lazy"]');
        self::assertSelectorNotExists('.fedstat script', 'HTML brut retiré.');
        self::assertSelectorNotExists('.fedstat a[href^="javascript"]', 'Lien dangereux refusé.');
        $this->client->request('GET', '/');
        self::assertSelectorTextContains('.fedstat h2', 'Bienvenue chez Alice', 'Aussi sur l’écran Publications.');
    }

    public function testImagesAreUploadedFromTheToolbarAndThePreviewRendersMarkdown(): void
    {
        $this->loginAsAdmin();
        $crawler = $this->client->request('GET', '/admin/instance');
        $token = (string) $crawler->filter('[data-controller="markdown-editor"]')->attr('data-markdown-editor-token-value');

        $this->client->request('POST', '/admin/instance/image', ['_token' => $token], ['image' => $this->jpeg(2400, 1600, 'paysage.jpg')]);
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        $url = $data['url'] ?? null;
        self::assertIsString($url);
        self::assertMatchesRegularExpression('#^/m/instance/[0-9a-f]{16}\.webp$#', $url);
        /** @var FilesystemOperator $storage */
        $storage = self::getContainer()->get('derivatives.storage');
        $size = getimagesizefromstring($storage->read(substr($url, \strlen(MediaUrls::PREFIX))));
        self::assertIsArray($size);
        self::assertSame([1200, 800], [$size[0], $size[1]], 'Réduite à 1200 pixels de large.');

        $path = sys_get_temp_dir().'/'.uniqid('socialize-', true).'.txt';
        file_put_contents($path, 'pas une image');
        $this->client->request('POST', '/admin/instance/image', ['_token' => $token], ['image' => new UploadedFile($path, 'faux.jpg', 'text/plain', null, true)]);
        self::assertResponseStatusCodeSame(422);
        $this->client->request('POST', '/admin/instance/image', ['_token' => 'faux'], ['image' => $this->jpeg(100, 100)]);
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', '/admin/instance/apercu', ['_token' => $token, 'body' => "- **un**\n- deux"]);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('<li><strong>un</strong></li>', (string) $this->client->getResponse()->getContent());
    }

    public function testOnlyTheAdministrator(): void
    {
        $this->register('admin');
        $this->login($this->register('hugo'));
        foreach ([['GET', '/admin/instance'], ['POST', '/admin/instance/image'], ['POST', '/admin/instance/apercu']] as [$method, $url]) {
            $this->client->request($method, $url);
            self::assertResponseStatusCodeSame(403, $url);
        }
    }

    private function loginAsAdmin(): void
    {
        $admin = $this->register('admin');
        $admin->promoteToAdmin();
        self::getContainer()->get('doctrine')->getManager()->flush();
        $this->login($admin);
    }
}
