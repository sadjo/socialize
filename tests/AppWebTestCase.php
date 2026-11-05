<?php

declare(strict_types=1);

namespace App\Tests;

use App\Core\Application\Account\RegisterAccount;
use App\Core\Domain\Account\User;
use Jcupitt\Vips\Image;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Base des tests fonctionnels : navigateur avec en-tête Origin (pour la protection
 * CSRF sans session), création de comptes et fabrication de médias de test.
 */
abstract class AppWebTestCase extends WebTestCase
{
    use ResetDatabase;

    protected KernelBrowser $client;

    protected function setUp(): void
    {
        new Filesystem()->remove(__DIR__.'/../var/test-storage');
        $this->client = static::createClient(server: ['HTTP_ORIGIN' => 'http://localhost']);
    }

    protected function register(string $username, ?string $email = null, string $password = 'mot-de-passe-solide'): User
    {
        /** @var RegisterAccount $register */
        $register = static::getContainer()->get(RegisterAccount::class);

        return $register->register($email ?? $username.'@exemple.test', $username, $password, ucfirst($username));
    }

    protected function login(User $user): User
    {
        $this->client->loginUser($user);

        return $user;
    }

    /**
     * Photo JPEG de test, avec des métadonnées EXIF dont une position GPS.
     */
    protected function jpeg(int $width = 800, int $height = 1000, string $name = 'photo.jpg'): UploadedFile
    {
        $path = sys_get_temp_dir().'/'.uniqid('socialize-', true).'.jpg';
        $image = Image::black($width, $height, ['bands' => 3])->add([120, 80, 200])->cast('uchar');
        $image = $image->copy();
        $image->set('exif-ifd0-Make', 'Fujifilm');
        $image->set('exif-ifd0-Model', 'X-T3');
        $image->writeToFile($path, ['Q' => 85]);

        return new UploadedFile($path, $name, 'image/jpeg', null, true);
    }

    protected function mp4(string $name = 'video.mp4', bool $sound = true): UploadedFile
    {
        $path = sys_get_temp_dir().'/'.uniqid('socialize-', true).'.mp4';
        $audio = $sound ? '-f lavfi -i sine=duration=2 -shortest -c:a aac' : '-an';
        exec(\sprintf('ffmpeg -v error -y -f lavfi -i testsrc=duration=2:size=320x240:rate=10 %s -c:v libx264 -pix_fmt yuv420p %s', $audio, escapeshellarg($path)), $output, $code);
        self::assertSame(0, $code, 'ffmpeg doit être installé pour les tests vidéo.');

        return new UploadedFile($path, $name, 'video/mp4', null, true);
    }
}
