<?php

declare(strict_types=1);

/*
 * Remplit l'Immich de test (compose.immich.yaml) pour développer l'intégration : compte administrateur,
 * clé API pour Socialize, une vingtaine de photos générées (dates, lieux, appareils, objectifs et GPS
 * variés, dans un fichier XMP lu par Immich), deux vidéos courtes et deux albums.
 * Relançable : Immich reconnaît les fichiers déjà envoyés (même empreinte) et les albums existants sont repris.
 *
 * Usage, dans le conteneur app : php docker/immich/seed.php [adresse d'Immich]
 * (make immich-seed ; par défaut http://host.docker.internal:2283)
 */

use Jcupitt\Vips\Image;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\HttpClientInterface;

require dirname(__DIR__, 2).'/vendor/autoload.php';

const ADMIN_EMAIL = 'admin@socialize.test';
const ADMIN_PASSWORD = 'socialize-immich';
const KEY_NAME = 'Socialize (développement)';
/** Droits dont Socialize a besoin (voir README, « Immich »). */
const KEY_PERMISSIONS = ['user.read', 'asset.read', 'asset.view', 'asset.download', 'asset.statistics', 'album.read', 'album.create', 'albumAsset.create'];

$base = rtrim($argv[1] ?? (getenv('IMMICH_URL') ?: 'http://host.docker.internal:2283'), '/').'/api';
$http = HttpClient::create(['timeout' => 60]);

/**
 * @param array<string, mixed> $options
 *
 * @return array<mixed>
 */
function call(HttpClientInterface $http, string $method, string $url, array $options = [], bool $allowFailure = false): array
{
    $response = $http->request($method, $url, $options);
    $status = $response->getStatusCode();
    if ($status >= 400) {
        if ($allowFailure) {
            return ['_status' => $status];
        }
        fwrite(\STDERR, \sprintf("Échec %s %s (%d) : %s\n", $method, $url, $status, $response->getContent(false)));
        exit(1);
    }
    $body = $response->getContent(false);

    return '' === $body ? [] : (array) json_decode($body, true, flags: \JSON_THROW_ON_ERROR);
}

// 1. Compte administrateur (créé au premier lancement), puis connexion.
call($http, 'POST', "$base/auth/admin-sign-up", ['json' => ['email' => ADMIN_EMAIL, 'password' => ADMIN_PASSWORD, 'name' => 'Camille Test']], allowFailure: true);
$login = call($http, 'POST', "$base/auth/login", ['json' => ['email' => ADMIN_EMAIL, 'password' => ADMIN_PASSWORD]]);
$auth = ['headers' => ['Authorization' => 'Bearer '.$login['accessToken']]];
echo "Connecté à Immich en tant que ".ADMIN_EMAIL."\n";

// Pas de conteneur machine learning dans l'Immich de test : on évite les tâches qui l'appellent.
$config = call($http, 'GET', "$base/system-config", $auth);
if (($config['machineLearning']['enabled'] ?? false) === true) {
    $config['machineLearning']['enabled'] = false;
    call($http, 'PUT', "$base/system-config", $auth + ['json' => $config]);
}

// 2. Clé API de Socialize : remplacée à chaque lancement (Immich ne réaffiche jamais un secret).
foreach (call($http, 'GET', "$base/api-keys", $auth) as $key) {
    if (KEY_NAME === ($key['name'] ?? null)) {
        call($http, 'DELETE', "$base/api-keys/{$key['id']}", $auth);
    }
}
$apiKey = call($http, 'POST', "$base/api-keys", $auth + ['json' => ['name' => KEY_NAME, 'permissions' => KEY_PERMISSIONS]]);

// 3. Photos et vidéos générées : [date, lieu, latitude, longitude, appareil, objectif, description, album].
$photos = [
    ['2026-11-08 08:12', 'Étretat', 49.7072, 0.2036, 'FUJIFILM X-T5', 'XF16-55mmF2.8 R LM WR', 'Lever de soleil sur la falaise d’Aval', 'Normandie'],
    ['2026-11-08 08:40', 'Étretat', 49.7101, 0.1987, 'FUJIFILM X-T5', 'XF16-55mmF2.8 R LM WR', 'L’Aiguille dans la brume', 'Normandie'],
    ['2026-11-08 11:05', 'Étretat', 49.7079, 0.2061, 'FUJIFILM X-T5', 'XF70-300mmF4-5.6 R LM OIS WR', 'Goélands sur les galets', 'Normandie'],
    ['2026-11-08 16:48', 'Fécamp', 49.7590, 0.3747, 'FUJIFILM X-T5', 'XF16-55mmF2.8 R LM WR', 'Le port au coucher du soleil', 'Normandie'],
    ['2026-11-09 10:20', 'Honfleur', 49.4194, 0.2330, 'Apple iPhone 16 Pro', 'iPhone 16 Pro back camera 6.765mm f/1.78', 'Le Vieux Bassin', 'Normandie'],
    ['2026-11-09 12:02', 'Honfleur', 49.4189, 0.2338, 'Apple iPhone 16 Pro', 'iPhone 16 Pro back camera 2.22mm f/2.2', 'Façades sur le quai Sainte-Catherine', 'Normandie'],
    ['2026-10-18 07:30', 'Chamonix', 45.9237, 6.8694, 'SONY ILCE-7M4', 'FE 24-70mm F2.8 GM II', 'Premiers rayons sur le mont Blanc', 'Montagne'],
    ['2026-10-18 09:15', 'Lac Blanc', 45.9810, 6.8920, 'SONY ILCE-7M4', 'FE 24-70mm F2.8 GM II', 'Reflets au lac Blanc', 'Montagne'],
    ['2026-10-18 10:02', 'Lac Blanc', 45.9815, 6.8931, 'SONY ILCE-7M4', 'FE 70-200mm F2.8 GM OSS II', 'Bouquetin sur les rochers', 'Montagne'],
    ['2026-10-19 13:40', 'Aiguille du Midi', 45.8786, 6.8873, 'SONY ILCE-7M4', 'FE 16-35mm F2.8 GM II', 'Arête de l’Aiguille du Midi', 'Montagne'],
    ['2026-10-19 17:55', 'Chamonix', 45.9210, 6.8700, 'SONY ILCE-7M4', 'FE 24-70mm F2.8 GM II', 'Lumière dorée sur les Drus', 'Montagne'],
    ['2026-09-27 19:10', 'Paris', 48.8584, 2.2945, 'Apple iPhone 16 Pro', 'iPhone 16 Pro back camera 6.765mm f/1.78', 'La tour Eiffel au crépuscule', null],
    ['2026-09-27 21:30', 'Paris', 48.8530, 2.3499, 'Apple iPhone 16 Pro', 'iPhone 16 Pro back camera 6.765mm f/1.78', 'Les quais de Seine la nuit', null],
    ['2026-09-28 10:45', 'Paris', 48.8867, 2.3431, 'Apple iPhone 16 Pro', 'iPhone 16 Pro back camera 2.22mm f/2.2', 'Escaliers de Montmartre', null],
    ['2026-08-14 14:20', 'Biarritz', 43.4832, -1.5586, 'Canon EOS R6m2', 'RF24-105mm F4 L IS USM', 'Surfeurs à la Grande Plage', null],
    ['2026-08-14 20:35', 'Biarritz', 43.4847, -1.5630, 'Canon EOS R6m2', 'RF24-105mm F4 L IS USM', 'Le Rocher de la Vierge', null],
    ['2026-08-15 09:10', 'Saint-Jean-de-Luz', 43.3887, -1.6631, 'Canon EOS R6m2', 'RF35mm F1.8 MACRO IS STM', 'Marché du matin', null],
    ['2026-07-03 06:50', 'Valensole', 43.8370, 5.9840, 'FUJIFILM X100VI', 'Fixe 23mm', 'Champs de lavande à l’aube', null],
    ['2026-07-03 18:30', 'Gordes', 43.9110, 5.2003, 'FUJIFILM X100VI', 'Fixe 23mm', 'Le village perché', null],
    ['2026-07-04 11:00', 'Roussillon', 43.9026, 5.2925, 'FUJIFILM X100VI', 'Fixe 23mm', 'Les ocres', null],
];
$videos = [
    ['2026-11-08 09:02', 'Étretat', 49.7090, 0.2010, 'Vagues au pied de la falaise', 'Normandie', 'mandelbrot'],
    ['2026-10-18 09:40', 'Lac Blanc', 45.9812, 6.8925, 'Panoramique sur le massif', 'Montagne', 'testsrc2'],
];

$dir = sys_get_temp_dir().'/immich-seed';
@mkdir($dir, 0o777, true);
$albums = [];
$created = 0;
$duplicates = 0;

$xmp = static function (string $date, float $lat, float $lng, ?string $make, ?string $lens, string $description): string {
    $dms = static function (float $value, string $pos, string $neg): string {
        $abs = abs($value);
        $deg = (int) floor($abs);

        return \sprintf('%d,%.4f%s', $deg, ($abs - $deg) * 60, $value >= 0 ? $pos : $neg);
    };
    $iso = new DateTimeImmutable($date, new DateTimeZone('Europe/Paris'))->format('Y-m-d\TH:i:sP');
    [$brand, $model] = null !== $make ? (explode(' ', $make, 2) + [1 => $make]) : [null, null];
    $camera = null !== $make ? \sprintf('<tiff:Make>%s</tiff:Make><tiff:Model>%s</tiff:Model><aux:Lens>%s</aux:Lens>', htmlspecialchars($brand), htmlspecialchars($model), htmlspecialchars((string) $lens)) : '';

    return <<<XMP
        <?xpacket begin="" id="W5M0MpCehiHzreSzNTczkc9d"?>
        <x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">
        <rdf:Description xmlns:exif="http://ns.adobe.com/exif/1.0/" xmlns:tiff="http://ns.adobe.com/tiff/1.0/" xmlns:aux="http://ns.adobe.com/exif/1.0/aux/" xmlns:photoshop="http://ns.adobe.com/photoshop/1.0/" xmlns:dc="http://purl.org/dc/elements/1.1/">
        <exif:DateTimeOriginal>{$iso}</exif:DateTimeOriginal><photoshop:DateCreated>{$iso}</photoshop:DateCreated>
        <exif:GPSLatitude>{$dms($lat, 'N', 'S')}</exif:GPSLatitude><exif:GPSLongitude>{$dms($lng, 'E', 'W')}</exif:GPSLongitude>
        {$camera}
        <dc:description><rdf:Alt><rdf:li xml:lang="x-default">{$description}</rdf:li></rdf:Alt></dc:description>
        </rdf:Description></rdf:RDF></x:xmpmeta>
        <?xpacket end="w"?>
        XMP;
};

$upload = static function (string $path, string $filename, string $date, string $sidecar) use ($http, $base, $auth, &$created, &$duplicates): string {
    $at = new DateTimeImmutable($date, new DateTimeZone('Europe/Paris'))->format(DATE_ATOM);
    $form = new FormDataPart([
        'assetData' => DataPart::fromPath($path, $filename),
        'sidecarData' => new DataPart($sidecar, pathinfo($filename, \PATHINFO_FILENAME).'.xmp', 'application/rdf+xml'),
        'fileCreatedAt' => $at,
        'fileModifiedAt' => $at,
        'filename' => $filename,
    ]);
    $result = call($http, 'POST', "$base/assets", ['headers' => $auth['headers'] + $form->getPreparedHeaders()->toArray(), 'body' => $form->bodyToIterable()]);
    'duplicate' === $result['status'] ? ++$duplicates : ++$created;

    return (string) $result['id'];
};

// Photos : dégradé coloré propre à chaque lieu, avec sa légende incrustée.
foreach ($photos as $i => [$date, $place, $lat, $lng, $make, $lens, $description, $album]) {
    $hue = crc32($place) % 360;
    $xyz = Image::xyz(1600, 1200);
    $x = $xyz->extract_band(0)->linear([255 / 1600], [0]);
    $y = $xyz->extract_band(1)->linear([255 / 1200], [0]);
    $rgb = $x->linear([0.6], [intdiv($hue, 2) % 120])->bandjoin([$y->linear([0.5], [60]), $x->add($y)->linear([0.3], [(360 - $hue) % 160])])->cast('uchar')->copy(['interpretation' => 'srgb']);
    $text = Image::text(\sprintf("%s\n%s", $place, $description), ['dpi' => 220, 'width' => 1400, 'align' => 'centre']);
    $label = $text->newFromImage([255, 255, 255])->bandjoin($text)->copy(['interpretation' => 'srgb']);
    $photo = $rgb->composite2($label, 'over', ['x' => 100, 'y' => 1200 - $text->height - 90]);
    $file = \sprintf('%s/photo-%02d.jpg', $dir, $i + 1);
    $photo->writeToFile($file, ['Q' => 88]);
    $id = $upload($file, \sprintf('IMG_%04d.jpg', 4200 + $i), $date, $xmp($date, $lat, $lng, $make, $lens, $description));
    if (null !== $album) {
        $albums[$album][] = $id;
    }
}

// Vidéos : quelques secondes d'image de test, avec du son.
foreach ($videos as $i => [$date, $place, $lat, $lng, $description, $album, $source]) {
    $file = \sprintf('%s/video-%d.mp4', $dir, $i + 1);
    if (!is_file($file)) {
        exec(\sprintf('ffmpeg -v error -y -f lavfi -i %s=size=1280x720:rate=25 -f lavfi -i sine=frequency=%d -t 4 -c:v libx264 -pix_fmt yuv420p -c:a aac -shortest %s', escapeshellarg($source), 330 + 110 * $i, escapeshellarg($file)), $output, $code);
        if (0 !== $code) {
            fwrite(\STDERR, "ffmpeg a échoué pour la vidéo $file\n");
            exit(1);
        }
    }
    $albums[$album][] = $upload($file, \sprintf('VID_%04d.mp4', 7300 + $i), $date, $xmp($date, $lat, $lng, null, null, $description));
}

// 4. Albums (repris s'ils existent déjà).
$existing = array_column(call($http, 'GET', "$base/albums", $auth), 'id', 'albumName');
foreach ($albums as $name => $ids) {
    $albumId = $existing[$name] ?? call($http, 'POST', "$base/albums", $auth + ['json' => ['albumName' => $name]])['id'];
    call($http, 'PUT', "$base/albums/$albumId/assets", $auth + ['json' => ['ids' => $ids]]);
}

$url = preg_replace('#/api$#', '', $base);
echo \sprintf("Médias : %d envoyés, %d déjà présents. Albums : %s.\n", $created, $duplicates, implode(', ', array_keys($albums)));
echo "\nImmich de test prêt : http://localhost:2283 (".ADMIN_EMAIL." / ".ADMIN_PASSWORD.")\n";
echo "Pour Socialize (Réglages › Immich) :\n";
echo "  Adresse du serveur : $url\n";
echo "  Clé API            : {$apiKey['secret']}\n";
