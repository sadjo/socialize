<?php

declare(strict_types=1);

namespace App\Media\Application\Library;

use App\Core\Domain\Account\Account;
use App\Core\Domain\Media\MediaAttachment;
use App\Core\Domain\Music\MusicChoice;
use App\Core\Domain\Post\CrosspostTarget;
use App\Core\Domain\Post\Post;
use App\Core\Domain\Post\Visibility;
use App\Core\Domain\Social\AudienceGroup;
use App\Core\Domain\Story\Story;
use App\Media\Application\MediaProcessingException;
use App\Media\Application\PostComposer;
use App\Media\Application\StoryComposer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\File;

/**
 * Publication à partir de médias de l'appareil et des photothèques externes du compte, dans l'ordre choisi.
 *
 * L'ordre est une liste de jetons : « n-<i> » pour le i-ème fichier envoyé, « <préfixe>-<référence> » pour un média
 * d'une photothèque (LibrarySource). Les originaux sont téléchargés, puis publiés comme des fichiers envoyés
 * (PostComposer, StoryComposer) ; les métadonnées connues de la photothèque complètent celles de l'original.
 */
final readonly class LibraryPublisher
{
    /**
     * @param iterable<LibrarySource> $sources
     */
    public function __construct(
        #[AutowireIterator(LibrarySource::TAG)]
        private iterable $sources,
        private PostComposer $posts,
        private StoryComposer $stories,
        private EntityManagerInterface $em,
        private Filesystem $filesystem = new Filesystem(),
    ) {
    }

    /**
     * @param list<string>           $order          jetons, dans l'ordre voulu (vide : les fichiers envoyés, dans leur ordre)
     * @param list<File>             $files          fichiers envoyés depuis l'appareil
     * @param array<string, ?string> $alts           texte alternatif par jeton
     * @param list<AudienceGroup>    $audienceGroups groupes d'abonnés (visibilité « Groupes »)
     * @param list<CrosspostTarget>  $crosspost      réseaux où l'envoyer aussi (Instagram…)
     */
    public function publishPost(Account $author, array $order, array $files, array $alts, string $caption, Visibility $visibility, ?string $contentWarning, ?string $location, ?MusicChoice $music = null, array $audienceGroups = [], array $crosspost = []): Post
    {
        $items = $this->resolve($author, $order, $files, Post::MAX_MEDIA);
        try {
            $post = $this->posts->publish(
                $author,
                $caption,
                array_map(static fn (array $item): array => ['file' => $item['file'], 'alt' => $alts[$item['token']] ?? null], $items),
                $visibility,
                $contentWarning,
                $location,
                $music,
                $audienceGroups,
                $crosspost,
            );
            $this->complete($author, array_values($post->getMedia()->toArray()), $items);

            return $post;
        } finally {
            $this->cleanUp($items);
        }
    }

    /**
     * @param list<string>          $order
     * @param list<File>            $files
     * @param list<AudienceGroup>   $audienceGroups groupes d'abonnés (public « Groupes »)
     * @param list<CrosspostTarget> $crosspost      réseaux où envoyer aussi chaque story (Instagram…)
     *
     * @return list<Story>
     */
    public function publishStories(Account $author, array $order, array $files, ?string $text, Visibility $audience, ?MusicChoice $music = null, array $audienceGroups = [], array $crosspost = []): array
    {
        $items = $this->resolve($author, $order, $files, Story::MAX_PER_UPLOAD);
        try {
            $stories = $this->stories->publish($author, array_column($items, 'file'), $text, $audience, $music, $audienceGroups, $crosspost);
            $this->complete($author, array_map(static fn (Story $s): ?MediaAttachment => $s->getMedia(), $stories), $items);

            return $stories;
        } finally {
            $this->cleanUp($items);
        }
    }

    /**
     * @param list<string> $order
     * @param list<File>   $files
     *
     * @return list<array{token: string, file: File, source: ?LibrarySource, media: ?LibraryMedia}>
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

        $items = [];
        try {
            foreach ($order as $token) {
                if (preg_match('/^n-(\d+)$/', $token, $m) && isset($files[(int) $m[1]])) {
                    $items[] = ['token' => $token, 'file' => $files[(int) $m[1]], 'source' => null, 'media' => null];
                    continue;
                }
                $source = preg_match('/^([a-z])-(.+)$/', $token, $m) ? $this->source($m[1]) : null;
                if (!$source instanceof LibrarySource) {
                    throw new MediaProcessingException('La sélection de médias est invalide. Rechargez la page et réessayez.');
                }
                $media = $source->fetch($author, $m[2]);
                $items[] = ['token' => $token, 'file' => $media->file, 'source' => $source, 'media' => $media];
            }
        } catch (MediaProcessingException $e) {
            $this->cleanUp($items);
            throw $e;
        }

        return $items;
    }

    private function source(string $prefix): ?LibrarySource
    {
        foreach ($this->sources as $source) {
            if ($source->prefix() === $prefix) {
                return $source;
            }
        }

        return null;
    }

    /**
     * Métadonnées de la photothèque dans les originaux publiés, puis suite propre à chaque photothèque.
     *
     * @param list<?MediaAttachment>                                                               $media médias publiés, dans l'ordre des sources
     * @param list<array{token: string, file: File, source: ?LibrarySource, media: ?LibraryMedia}> $items
     */
    private function complete(Account $author, array $media, array $items): void
    {
        /** @var array<string, array{source: LibrarySource, references: list<string>}> $published */
        $published = [];
        foreach ($items as $position => $item) {
            if ($item['source'] instanceof LibrarySource && $item['media'] instanceof LibraryMedia && ($media[$position] ?? null) instanceof MediaAttachment) {
                $media[$position]->addExif($item['media']->exif);
                $published[$item['source']->prefix()] ??= ['source' => $item['source'], 'references' => []];
                $published[$item['source']->prefix()]['references'][] = $item['media']->reference;
            }
        }
        $this->em->flush();

        foreach ($published as $entry) {
            $entry['source']->published($author, $entry['references']);
        }
    }

    /**
     * Supprime les originaux téléchargés (jamais les fichiers envoyés depuis l'appareil).
     *
     * @param list<array{token: string, file: File, source: ?LibrarySource, media: ?LibraryMedia}> $items
     */
    private function cleanUp(array $items): void
    {
        $this->filesystem->remove(array_map(
            static fn (array $item): string => $item['file']->getPathname(),
            array_filter($items, static fn (array $item): bool => $item['media'] instanceof LibraryMedia),
        ));
    }
}
