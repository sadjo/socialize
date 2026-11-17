<?php

declare(strict_types=1);

namespace App\Tests\Web;

use App\Core\Domain\Account\Account;
use App\Core\Infrastructure\Doctrine\AccountRepository;
use App\Media\Application\MediaUrls;
use App\Tests\AppWebTestCase;
use Jcupitt\Vips\Image;
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
        self::assertSelectorExists('[data-controller="image-crop"] label.pick-avatar[for="profile_settings_avatar"]', 'La photo est le libellé de son champ fichier.');
        self::assertSelectorExists('[data-controller="image-crop"] label.pick-cover[for="profile_settings_cover"]', 'La couverture aussi.');
        self::assertSelectorExists('input[type="file"]#profile_settings_avatar.sr-only[data-action="image-crop#pick"]');
        self::assertSelectorNotExists('.prof-cover img');
        self::assertSelectorExists('input[type="hidden"]#profile_settings_coverCrop[data-image-crop-target="crop"]', 'Le cadre part dans un champ caché.');
        self::assertSelectorExists('button[data-action="image-crop#open"][hidden]', 'Rien à repositionner sans image.');
    }

    public function testTheChosenFrameIsCutThenRepositionedWithoutSendingTheFileAgain(): void
    {
        $camille = $this->login($this->register('camille'));

        // Moitié haute rouge, moitié basse bleue : le cadre choisi dit laquelle garder.
        $this->client->request('GET', '/reglages');
        $this->client->submitForm('Enregistrer', [
            'profile_settings[cover]' => $this->twoColours(),
            'profile_settings[coverCrop]' => '0,0.6,1,0.25',
            'profile_settings[avatar]' => $this->twoColours(),
            'profile_settings[avatarCrop]' => '0.25,0,0.5,0.5',
        ]);
        self::assertResponseRedirects('/reglages');

        $account = $this->reload($camille->getAccount()->getId()->toRfc4122());
        self::assertSame('0,0.6,1,0.25', $account->getCoverCrop());
        self::assertNotNull($account->getCoverSourcePath(), 'Source gardée pour recadrer plus tard.');
        self::assertSame('blue', $this->colourOf((string) $account->getCoverPath()));
        self::assertSame('red', $this->colourOf((string) $account->getAvatarPath()), 'Photo cadrée dans la moitié haute.');
        $source = $account->getCoverSourcePath();
        $before = $account->getCoverPath();

        $crawler = $this->client->request('GET', '/reglages');
        self::assertSelectorExists('button[data-action="image-crop#open"]:not([hidden])', 'L’image se repositionne.');
        self::assertSame('0,0.6,1,0.25', $crawler->filter('.pick-block')->first()->attr('data-image-crop-crop-value'));
        $this->client->submitForm('Enregistrer', ['profile_settings[coverCrop]' => '0,0,1,0.25']);
        self::assertResponseRedirects('/reglages');

        $account = $this->reload($camille->getAccount()->getId()->toRfc4122());
        self::assertSame($source, $account->getCoverSourcePath(), 'Même source.');
        self::assertNotSame($before, $account->getCoverPath());
        self::assertSame('red', $this->colourOf((string) $account->getCoverPath()), 'Repositionnée vers le haut.');

        $this->client->request('GET', '/reglages');
        $this->client->submitForm('Enregistrer', ['profile_settings[removeCover]' => true]);
        $account = $this->reload($camille->getAccount()->getId()->toRfc4122());
        self::assertNull($account->getCoverSourcePath());
        self::assertFalse($this->storage()->fileExists($source), 'Source effacée avec la couverture.');
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
        self::assertSame([1600, 400], [$width, $height], 'Bandeau 4:1.');

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

    private function twoColours(): UploadedFile
    {
        $red = Image::black(800, 400, ['bands' => 3])->add([220, 20, 20])->cast('uchar');
        $blue = Image::black(800, 400, ['bands' => 3])->add([20, 20, 220])->cast('uchar');
        $path = sys_get_temp_dir().'/'.uniqid('socialize-', true).'.png';
        $red->join($blue, 'vertical')->writeToFile($path);

        return new UploadedFile($path, 'deux-couleurs.png', 'image/png', null, true);
    }

    private function colourOf(string $path): string
    {
        $image = Image::newFromBuffer($this->storage()->read($path));
        [$r, , $b] = $image->getpoint(intdiv($image->width, 2), intdiv($image->height, 2));

        return $r > $b ? 'red' : 'blue';
    }

    private function reload(string $id): Account
    {
        self::getContainer()->get('doctrine')->getManager()->clear();
        $account = self::getContainer()->get(AccountRepository::class)->find($id);
        self::assertInstanceOf(Account::class, $account);

        return $account;
    }

    private function storage(): FilesystemOperator
    {
        /** @var FilesystemOperator $storage */
        $storage = self::getContainer()->get('derivatives.storage');

        return $storage;
    }

    /**
     * Dimensions d'une copie publique, lue dans le stockage (les fichiers /m/ sont servis par le serveur web).
     *
     * @return array{int, int}
     */
    private function imageSize(string $url): array
    {
        self::assertStringStartsWith(MediaUrls::PREFIX, $url);
        $size = getimagesizefromstring($this->storage()->read(substr($url, \strlen(MediaUrls::PREFIX))));
        self::assertIsArray($size, 'Image lisible.');

        return [$size[0], $size[1]];
    }
}
