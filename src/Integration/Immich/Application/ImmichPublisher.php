<?php

declare(strict_types=1);

namespace App\Integration\Immich\Application;

use App\Core\Domain\Account\Account;
use App\Core\Domain\Media\MediaAttachment;
use App\Core\Domain\Music\MusicChoice;
use App\Core\Domain\Post\Post;
use App\Core\Domain\Post\Visibility;
use App\Core\Domain\Story\Story;
use App\Integration\Immich\Domain\ImmichConnection;
use App\Integration\Immich\Infrastructure\ImmichClient;
use App\Media\Application\MediaProcessingException;
use App\Media\Application\MediaRules;
use App\Media\Application\PostComposer;
use App\Media\Application\StoryComposer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Publication à partir de médias de l'appareil et de la photothèque Immich, dans l'ordre choisi.
 *
 * L'ordre est une liste de jetons : « n-<i> » pour le i-ème fichier envoyé, « i-<id> » pour un média Immich.
 * Les originaux Immich sont téléchargés, puis publiés comme des fichiers envoyés (PostComposer, StoryComposer) ;
 * l'EXIF connu d'Immich (date, lieu, objectif, GPS) complète celui de l'original.
 */
final readonly class ImmichPublisher
{
    public function __construct(
        private ImmichConnections $connections,
        private ImmichClient $client,
        private PostComposer $posts,
        private StoryComposer $stories,
        private EntityManagerInterface $em,
        private MessageBusInterface $bus,
        private Filesystem $filesystem = new Filesystem(),
    ) {
    }

    /**
     * @param list<string>           $order jetons, dans l'ordre voulu (vide : les fichiers envoyés, dans leur ordre)
     * @param list<File>             $files fichiers envoyés depuis l'appareil
     * @param array<string, ?string> $alts  texte alternatif par jeton
     */
    public function publishPost(Account $author, array $order, array $files, array $alts, string $caption, Visibility $visibility, ?string $contentWarning, ?string $location, ?MusicChoice $music = null): Post
    {
        $sources = $this->resolve($author, $order, $files, Post::MAX_MEDIA);
        try {
            $post = $this->posts->publish(
                $author,
                $caption,
                array_map(static fn (array $s): array => ['file' => $s['file'], 'alt' => $alts[$s['token']] ?? null], $sources['items']),
                $visibility,
                $contentWarning,
                $location,
                $music,
            );
            $this->complete(array_values($post->getMedia()->toArray()), $sources);

            return $post;
        } finally {
            $this->filesystem->remove($sources['temps']);
        }
    }

    /**
     * @param list<string> $order
     * @param list<File>   $files
     *
     * @return list<Story>
     */
    public function publishStories(Account $author, array $order, array $files, ?string $text, Visibility $audience, ?MusicChoice $music = null): array
    {
        $sources = $this->resolve($author, $order, $files, Story::MAX_PER_UPLOAD);
        try {
            $stories = $this->stories->publish($author, array_column($sources['items'], 'file'), $text, $audience, $music);
            $this->complete(array_map(static fn (Story $s): ?MediaAttachment => $s->getMedia(), $stories), $sources);

            return $stories;
        } finally {
            $this->filesystem->remove($sources['temps']);
        }
    }

    /**
     * @param list<string> $order
     * @param list<File>   $files
     *
     * @return array{items: list<array{token: string, file: File, asset: ?ImmichAsset}>, temps: list<string>, connection: ?ImmichConnection}
     */
    private function resolve(Account $author, array $order, array $files, int $max): array
    {
        $order = [] === $order ? array_map(static fn (int $i): string => 'n-'.$i, array_keys($files)) : array_values(array_unique($order));
        if ([] === $order) {
            throw new MediaProcessingException('Ajoutez au moins une photo ou une vidéo.');
        }
        if (\count($order) > $max) {
            throw new MediaProcessingException(\sprintf('Vous pouvez ajouter %d médias au plus.', $max));
        }

        $connection = null;
        $items = [];
        $temps = [];
        try {
            foreach ($order as $token) {
                if (preg_match('/^n-(\d+)$/', $token, $m) && isset($files[(int) $m[1]])) {
                    $items[] = ['token' => $token, 'file' => $files[(int) $m[1]], 'asset' => null];
                    continue;
                }
                if (!preg_match('/^i-([0-9a-f-]{36})$/i', $token, $m)) {
                    throw new MediaProcessingException('La sélection de médias est invalide. Rechargez la page et réessayez.');
                }
                $connection ??= $this->connections->of($author) ?? throw new MediaProcessingException('Connectez Immich dans les réglages pour publier depuis votre photothèque.');
                [$server, $key] = $this->connections->credentials($connection);

                $asset = $this->client->asset($server, $key, $m[1]);
                $this->checkAsset($asset);
                $path = $this->filesystem->tempnam(sys_get_temp_dir(), 'socialize-immich-', '.'.(pathinfo($asset->fileName, \PATHINFO_EXTENSION) ?: ($asset->video ? 'mp4' : 'jpg')));
                $temps[] = $path;
                $this->client->download($server, $key, $asset->id, $path);
                $items[] = ['token' => $token, 'file' => new File($path), 'asset' => $asset];
            }
        } catch (ImmichException $e) {
            $this->filesystem->remove($temps);
            throw new MediaProcessingException($e->getMessage(), $e->getCode(), previous: $e);
        } catch (MediaProcessingException $e) {
            $this->filesystem->remove($temps);
            throw $e;
        }

        return ['items' => $items, 'temps' => $temps, 'connection' => $connection];
    }

    private function checkAsset(ImmichAsset $asset): void
    {
        $limit = $asset->video ? MediaRules::MAX_VIDEO_SIZE : MediaRules::MAX_IMAGE_SIZE;
        if (null !== $asset->size && $asset->size > (int) $limit * 1024 * 1024) {
            throw new MediaProcessingException(\sprintf('« %s » dépasse la taille maximale (%so).', $asset->fileName, $limit));
        }
        if (null !== $asset->mimeType && !MediaRules::typeOf($asset->mimeType) instanceof \App\Core\Domain\Media\MediaType) {
            throw new MediaProcessingException(\sprintf('Le format de « %s » (%s) n’est pas accepté.', $asset->fileName, $asset->mimeType));
        }
    }

    /**
     * EXIF d'Immich dans les originaux publiés, puis ajout à l'album « Publié sur Socialize ».
     *
     * @param list<?MediaAttachment>                                                                                                        $media   médias publiés, dans l'ordre des sources
     * @param array{items: list<array{token: string, file: File, asset: ?ImmichAsset}>, temps: list<string>, connection: ?ImmichConnection} $sources
     */
    private function complete(array $media, array $sources): void
    {
        $published = [];
        foreach ($sources['items'] as $position => $source) {
            if ($source['asset'] instanceof ImmichAsset && ($media[$position] ?? null) instanceof MediaAttachment) {
                $media[$position]->addExif($source['asset']->exif);
                $published[] = $source['asset']->id;
            }
        }
        $this->em->flush();

        $connection = $sources['connection'];
        if ($connection instanceof ImmichConnection && $connection->addsToAlbum() && [] !== $published) {
            $this->bus->dispatch(new AddToImmichAlbum($connection->getId()->toRfc4122(), $published));
        }
    }
}
