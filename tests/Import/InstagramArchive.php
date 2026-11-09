<?php

declare(strict_types=1);

namespace App\Tests\Import;

use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Archive Instagram de test, sur le modèle d'un export JSON réel (novembre 2026) :
 * chemins, structure des fichiers, encodage cassé des textes et métadonnées en français.
 */
final class InstagramArchive
{
    public const int SUMMER = 1560513600;   // 14 juin 2019 : carrousel (2 photos)
    public const int SPRING = 1456833600;   // 1er mars 2016 : une photo, une autre absente de l'archive
    public const int WINTER = 1484136000;   // 11 janvier 2017 : une photo
    public const int ARCHIVED = 1527854400; // 1er juin 2018 : publication archivée
    public const int STORY = 1592136000;    // 14 juin 2020 : story
    public const int REEL = 1600000000;     // 13 septembre 2020 : vidéo (si demandée)

    /**
     * @param \Closure(): UploadedFile        $photo fabrique une photo JPEG
     * @param (\Closure(): UploadedFile)|null $video fabrique une vidéo MP4 (publication vidéo en plus)
     */
    public static function build(\Closure $photo, ?\Closure $video = null): string
    {
        $path = sys_get_temp_dir().'/'.uniqid('instagram-', true).'.zip';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);

        $media = static function (string $uri, int $timestamp, string $title = '') use ($zip, $photo): array {
            $zip->addFromString($uri, (string) file_get_contents($photo()->getPathname()));

            return ['uri' => $uri, 'creation_timestamp' => $timestamp, 'media_metadata' => ['camera_metadata' => ['has_camera_metadata' => false]], 'title' => $title, 'cross_post_source' => ['source_app' => 'FB']];
        };
        // « Été à Biarritz #surf 🌊 » tel que l'écrit Instagram : chaque octet UTF-8 devient un caractère.
        $broken = mb_convert_encoding('Été à Biarritz #surf 🌊', 'UTF-8', 'ISO-8859-1');

        $posts = [
            ['media' => [$media('media/posts/17900000000000001.jpg', self::SUMMER), $media('media/posts/17900000000000002.jpg', self::SUMMER)], 'title' => $broken, 'creation_timestamp' => self::SUMMER],
            ['media' => [
                $media('media/posts/17900000000000003.jpg', self::SPRING, 'Premier printemps'),
                ['uri' => 'media/posts/17900000000000004.jpg', 'creation_timestamp' => self::SPRING, 'title' => ''],
            ]],
            ['media' => [$media('media/posts/17900000000000005.jpg', self::WINTER)], 'title' => 'Neige', 'creation_timestamp' => self::WINTER],
        ];
        if ($video instanceof \Closure) {
            $zip->addFromString('media/posts/202009/17900000000000008.mp4', (string) file_get_contents($video()->getPathname()));
            $posts[] = ['media' => [['uri' => 'media/posts/202009/17900000000000008.mp4', 'creation_timestamp' => self::REEL, 'title' => 'Vague']], 'title' => 'Vague', 'creation_timestamp' => self::REEL];
        }
        $zip->addFromString('your_instagram_activity/media/posts_1.json', self::json($posts));
        $zip->addFromString('your_instagram_activity/media/archived_posts.json', self::json(['ig_archived_post_media' => [
            ['media' => [$media('media/posts/17900000000000006.jpg', self::ARCHIVED)], 'title' => 'Ancienne photo', 'creation_timestamp' => self::ARCHIVED],
        ]]));
        $story = $media('media/stories/202006/17900000000000007.jpg', self::STORY, 'Coucher de soleil');
        $story['media_metadata']['photo_metadata'] = ['exif_data' => [['latitude' => 43.48, 'longitude' => -1.55, 'lens_model' => 'iPhone 11 back camera', 'device_id' => 'secret']]];
        $zip->addFromString('your_instagram_activity/media/stories.json', self::json(['ig_stories' => [$story]]));
        // Métadonnées des publications : libellés traduits (et cassés), date à une seconde près.
        $zip->addFromString('your_instagram_activity/media/posts.json', self::json([
            ['timestamp' => self::SUMMER + 1, 'media' => [], 'label_values' => [
                ['label' => 'Latitude', 'value' => '43.4832'],
                ['label' => 'Longitude', 'value' => '-1.5586'],
                ['label' => mb_convert_encoding('Légende', 'UTF-8', 'ISO-8859-1'), 'value' => $broken],
            ], 'fbid' => '1'],
        ]));
        // Ce qui ne doit jamais être lu.
        $zip->addFromString('your_instagram_activity/messages/inbox/ami_1/message_1.json', self::json(['messages' => [['content' => 'privé']]]));
        $zip->close();

        return $path;
    }

    private static function json(mixed $data): string
    {
        // Comme Instagram : tout caractère non ASCII est échappé (Ã…).
        return (string) json_encode($data, \JSON_UNESCAPED_SLASHES);
    }
}
