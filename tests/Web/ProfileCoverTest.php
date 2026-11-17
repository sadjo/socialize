<?php

declare(strict_types=1);

namespace App\Tests\Web;

use App\Media\Application\MediaUrls;
use App\Tests\AppWebTestCase;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Image de couverture du profil et photo changées d'un clic dans les réglages ; la couverture suit la fédération.
 */
final class ProfileCoverTest extends AppWebTestCase
{
    public function testTheSettingsChangeImagesByClickingOnThem(): void
    {
        $this->login($this->register('camille'));

        $this->client->request('GET', '/reglages');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-controller="image-pick"] label.pick-avatar[for="profile_settings_avatar"]', 'La photo est le libellé de son champ fichier.');
        self::assertSelectorExists('[data-controller="image-pick"] label.pick-cover[for="profile_settings_cover"]', 'La couverture aussi.');
        self::assertSelectorExists('input[type="file"]#profile_settings_avatar.sr-only[data-action="image-pick#preview"]');
        self::assertSelectorNotExists('.prof-cover img');
    }

    public function testACoverIsShownOnTheProfileFederatedThenRemoved(): void
    {
        $this->login($this->register('camille'));

        $this->client->request('GET', '/@camille');
        self::assertSelectorExists('a.prof-cover.empty[href="/reglages"]', 'Invitation à ajouter une couverture, pour le titulaire.');

        $this->client->request('GET', '/reglages');
        $this->client->submitForm('Enregistrer', [
            'profile_settings[cover]' => $this->jpeg(1600, 900, 'couverture.jpg'),
        ]);
        self::assertResponseRedirects('/reglages');

        $this->client->request('GET', '/@camille');
        $src = $this->client->getCrawler()->filter('.prof-cover img')->attr('src');
        self::assertIsString($src);
        self::assertStringContainsString('/covers/', $src);
        [$width, $height] = $this->imageSize($src);
        self::assertSame([1500, 500], [$width, $height], 'Bandeau 3:1, comme Mastodon.');

        $this->client->request('GET', '/users/camille', server: ['HTTP_ACCEPT' => 'application/activity+json']);
        $actor = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($actor);
        self::assertIsArray($actor['image'] ?? null);
        self::assertSame('Image', $actor['image']['type']);
        self::assertSame('http://localhost'.$src, $actor['image']['url']);

        $this->client->request('GET', '/reglages');
        $this->client->submitForm('Enregistrer', ['profile_settings[removeCover]' => true]);
        self::assertResponseRedirects('/reglages');
        $this->client->request('GET', '/@camille');
        self::assertSelectorNotExists('.prof-cover img');
    }

    public function testAnUnreadableCoverIsRejectedOnItsField(): void
    {
        $this->login($this->register('camille'));
        $path = sys_get_temp_dir().'/'.uniqid('socialize-', true).'.txt';
        file_put_contents($path, 'pas une image');

        $this->client->request('GET', '/reglages');
        $this->client->submitForm('Enregistrer', [
            'profile_settings[cover]' => new UploadedFile($path, 'faux.jpg', 'image/jpeg', null, true),
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('.pick-block .field-errors');
    }

    /**
     * Dimensions d'une copie publique, lue dans le stockage (les fichiers /m/ sont servis par le serveur web).
     *
     * @return array{int, int}
     */
    private function imageSize(string $url): array
    {
        self::assertStringStartsWith(MediaUrls::PREFIX, $url);
        /** @var FilesystemOperator $storage */
        $storage = self::getContainer()->get('derivatives.storage');
        $size = getimagesizefromstring($storage->read(substr($url, \strlen(MediaUrls::PREFIX))));
        self::assertIsArray($size, 'Image lisible.');

        return [$size[0], $size[1]];
    }
}
