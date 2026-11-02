<?php

declare(strict_types=1);

namespace App\Web\Command;

use App\Core\Application\Account\RegisterAccount;
use App\Core\Application\Social\SocialActions;
use App\Core\Domain\Account\User;
use App\Core\Domain\Post\Visibility;
use App\Core\Infrastructure\Doctrine\UserRepository;
use App\Media\Application\PostComposer;
use Jcupitt\Vips\Image;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\File;

/**
 * Remplit une instance de développement avec des comptes et des publications d'exemple.
 */
#[AsCommand(name: 'app:demo:seed', description: 'Crée des comptes et des publications de démonstration (hors production)')]
final readonly class SeedDemoCommand
{
    private const array PALETTES = [
        [[242, 147, 122], [42, 29, 82]],
        [[95, 155, 214], [228, 238, 246]],
        [[244, 162, 89], [111, 59, 36]],
        [[143, 199, 166], [33, 64, 47]],
        [[185, 168, 230], [69, 56, 107]],
        [[247, 169, 154], [22, 61, 88]],
    ];

    public function __construct(
        private RegisterAccount $register,
        private UserRepository $users,
        private PostComposer $composer,
        private SocialActions $social,
        #[Autowire(param: 'kernel.environment')]
        private string $environment,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        if ('prod' === $this->environment) {
            $io->error('Cette commande est réservée au développement.');

            return Command::FAILURE;
        }

        $camille = $this->user('camille', 'Camille Roux');
        $hugo = $this->user('hugo', 'Hugo Bernard');
        $ines = $this->user('ines.lmb', 'Inès Lambert');

        $this->social->follow($camille->getAccount(), $hugo->getAccount());
        $this->social->follow($hugo->getAccount(), $camille->getAccount());
        $this->social->follow($ines->getAccount(), $camille->getAccount());

        $posts = [
            [$camille, 'Dernière baignade de la saison. L’eau était à 14 °C, personne d’autre n’a osé. #normandie #mer', 'Étretat, Normandie', [5, 1], Visibility::Public, null],
            [$hugo, 'Brume du matin sous les hêtres. #forêt #bretagne', 'Forêt de Brocéliande', [3, 3], Visibility::Public, null],
            [$ines, 'Une mante religieuse sur la lavande. Macro 100 mm, à main levée. #macro', 'Jardin des Plantes, Paris', [4], Visibility::Public, 'Macro d’insecte en gros plan'],
            [$camille, 'Lever de soleil sur le lac, pour mes abonnés seulement.', 'Lac Blanc, Chamonix', [1, 0, 4], Visibility::Followers, null],
        ];
        foreach ($posts as [$user, $caption, $location, $palettes, $visibility, $warning]) {
            $uploads = [];
            foreach ($palettes as $i => $palette) {
                $uploads[] = ['file' => $this->image($palette, $i), 'alt' => 'Paysage d’exemple '.($i + 1)];
            }
            $post = $this->composer->publish($user->getAccount(), $caption, $uploads, $visibility, $warning, $location);
            $this->social->like($hugo->getAccount(), $post);
        }

        $io->success('Comptes créés (mot de passe : demo-socialize) : camille@exemple.test, hugo@exemple.test, ines.lmb@exemple.test');

        return Command::SUCCESS;
    }

    private function user(string $username, string $name): User
    {
        return $this->users->findOneByEmail($username.'@exemple.test')
            ?? $this->register->register($username.'@exemple.test', $username, 'demo-socialize', $name);
    }

    private function image(int $palette, int $variant): File
    {
        [$from, $to] = self::PALETTES[$palette % \count(self::PALETTES)];
        $width = 0 === $variant % 2 ? 1080 : 1350;
        $height = 0 === $variant % 2 ? 1350 : 1080;

        $ramp = Image::xyz($width, $height)->extract_band(1)->divide($height);
        $bands = [];
        foreach ([0, 1, 2] as $band) {
            $bands[] = $ramp->multiply($to[$band] - $from[$band])->add($from[$band]);
        }
        $image = $bands[0]->bandjoin([$bands[1], $bands[2]])->cast('uchar');
        $sun = Image::black($width, $height)->draw_circle([255], (int) ($width * 0.65), (int) ($height * 0.3), (int) ($width * 0.08), ['fill' => true])->gaussblur(12);
        $image = $image->add($sun->multiply(0.6))->cast('uchar');

        $path = sys_get_temp_dir().'/'.uniqid('demo-', true).'.jpg';
        $image->writeToFile($path, ['Q' => 88]);

        return new File($path);
    }
}
