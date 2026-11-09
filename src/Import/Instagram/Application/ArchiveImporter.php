<?php

declare(strict_types=1);

namespace App\Import\Instagram\Application;

use App\Core\Domain\Event\PostPublished;
use App\Core\Domain\Media\MediaAttachment;
use App\Core\Domain\Post\Post;
use App\Core\Domain\Post\Visibility;
use App\Core\Domain\Story\Story;
use App\Import\Instagram\Domain\ImportItem;
use App\Import\Instagram\Domain\ImportStatus;
use App\Import\Instagram\Domain\InstagramImport;
use App\Import\Instagram\Domain\ItemKind;
use App\Import\Instagram\Domain\ItemStatus;
use App\Import\Instagram\Domain\Placement;
use App\Import\Instagram\Infrastructure\ArchiveReader;
use App\Import\Instagram\Infrastructure\Doctrine\ImportItemRepository;
use App\Media\Application\MediaIngestor;
use App\Media\Application\MediaProcessingException;
use App\Media\Application\MediaRules;
use App\Media\Application\ProcessVideo;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Import des éléments analysés : publications (dates d'origine, carrousels, EXIF), publications
 * archivées et stories (rangées dans les archives, puisqu'elles ont expiré depuis longtemps).
 */
final readonly class ArchiveImporter
{
    public const int BATCH_SIZE = 5;

    public function __construct(
        private EntityManagerInterface $em,
        private ImportItemRepository $items,
        private ArchiveUploads $uploads,
        private MediaIngestor $ingestor,
        private MessageBusInterface $bus,
        private EventDispatcherInterface $events,
        private ClockInterface $clock,
        private Filesystem $filesystem = new Filesystem(),
    ) {
    }

    public function launch(InstagramImport $import, Placement $placement, bool $keepOriginalDates, bool $importStories): void
    {
        $import->start($placement, $keepOriginalDates, $importStories);
        $import->addLog(\sprintf('Import lancé · %s', $placement->label()));
        $this->em->flush();
        $this->bus->dispatch(new ImportInstagramBatch($import->getId()->toRfc4122()));
    }

    /**
     * Importe le lot suivant.
     *
     * @return bool vrai s'il reste des éléments à importer
     */
    public function importBatch(InstagramImport $import, int $limit = self::BATCH_SIZE): bool
    {
        if (ImportStatus::Importing !== $import->getStatus()) {
            return false;
        }
        $batch = $this->items->nextPending($import, $limit);
        if ([] === $batch) {
            $this->finish($import);

            return false;
        }

        $archive = new ArchiveReader(array_column($import->getParts(), 'path'));
        try {
            foreach ($batch as $item) {
                $this->importItem($import, $item, $archive);
                $this->em->flush();
            }
        } finally {
            $archive->close();
        }

        return true;
    }

    private function finish(InstagramImport $import): void
    {
        $counts = $this->items->countByStatus($import);
        $import->addLog(\sprintf(
            '✓ Import terminé · %d importé%s, %d ignoré%s, %d déjà présent%s, %d en échec',
            $counts['imported'], $counts['imported'] > 1 ? 's' : '',
            $counts['skipped'], $counts['skipped'] > 1 ? 's' : '',
            $counts['duplicate'], $counts['duplicate'] > 1 ? 's' : '',
            $counts['failed'],
        ));
        $import->complete($this->clock->now(), [
            'imported' => $counts['imported'],
            'skipped' => $counts['skipped'],
            'duplicate' => $counts['duplicate'],
            'failed' => $counts['failed'],
        ]);
        $this->em->flush();
        $this->uploads->cleanUp($import);
        $this->convertVideos($import);
    }

    /**
     * Les vidéos sont converties une fois tous les éléments importés : dans la file des tâches,
     * elles ne s'intercalent pas entre les lots de l'import, qui avance ainsi d'une traite.
     */
    public function convertVideos(InstagramImport $import): void
    {
        foreach ($this->items->pendingVideoIds($import) as $id) {
            $this->bus->dispatch(new ProcessVideo($id));
        }
    }

    private function importItem(InstagramImport $import, ImportItem $item, ArchiveReader $archive): void
    {
        $date = $item->getTakenAt()->format('Y-m-d');
        if ($this->items->alreadyImported($import->getAccount(), $item->getFingerprint())) {
            $item->skip(ItemStatus::Duplicate, 'Déjà importé lors d’un import précédent.');

            return;
        }
        if (ItemKind::Story === $item->getKind()) {
            if (!$import->importsStories()) {
                $item->skip(ItemStatus::Skipped, 'Stories non importées (option).');

                return;
            }
            $this->importStory($import, $item, $archive);
            $import->addLog(\sprintf('✓ %s · story rangée dans les archives', $date));

            return;
        }

        $placement = $item->placement();
        if (Placement::Skip === $placement) {
            $item->skip(ItemStatus::Skipped, 'Non importée (choix fait avant l’import).');
            $import->addLog(\sprintf('– %s · publication non importée (votre choix)', $date));

            return;
        }
        [$post, $missing] = $this->importPost($import, $item, $placement, $archive);
        if (!$post instanceof Post) {
            return;
        }
        $import->addLog(\sprintf(
            '✓ %s · « %s » · %d média%s%s%s',
            $date,
            mb_strimwidth('' !== $item->getCaption() ? $item->getCaption() : 'sans légende', 0, 40, '…'),
            $post->getMedia()->count(),
            $post->getMedia()->count() > 1 ? 's' : '',
            $post->isArchived() ? ' · archivée' : '',
            $missing > 0 ? \sprintf(' · %d absent%s de l’archive', $missing, $missing > 1 ? 's' : '') : '',
        ));
    }

    /**
     * @return array{0: ?Post, 1: int} publication créée et nombre de médias absents
     */
    private function importPost(InstagramImport $import, ImportItem $item, Placement $placement, ArchiveReader $archive): array
    {
        $whole = $import->getPlacement();
        $publishedAt = $import->keepsOriginalDates() ? $item->getTakenAt() : $this->clock->now();
        $post = Post::imported($import->getAccount(), $item->getCaption(), $placement->visibility($whole), $item->getTakenAt(), $publishedAt, $placement->announces());

        $stored = [];
        $missing = 0;
        try {
            foreach ($item->getMediaUris() as $uri) {
                if (\count($stored) >= Post::MAX_MEDIA) {
                    break;
                }
                $media = $this->attach($post, $uri, \count($stored), $archive, $item->getExif());
                if ($media instanceof MediaAttachment) {
                    $stored[] = $media;
                } else {
                    ++$missing;
                }
            }
        } catch (MediaProcessingException $e) {
            array_walk($stored, $this->ingestor->delete(...));
            $item->skip(ItemStatus::Failed, $e->getMessage());
            $import->addLog(\sprintf('! %s · échec : %s', $item->getTakenAt()->format('Y-m-d'), $e->getMessage()));

            return [null, $missing];
        }
        if ([] === $stored) {
            $item->skip(ItemStatus::Failed, 'Aucun de ses médias n’est dans l’archive.');
            $import->addLog(\sprintf('! %s · publication sans média dans l’archive : ignorée', $item->getTakenAt()->format('Y-m-d')));

            return [null, $missing];
        }

        if (Placement::Archive === $placement) {
            $post->archive($this->clock->now());
        }
        $this->em->persist($post);
        $item->imported($post->getId(), $missing > 0 ? \sprintf('%d média%s absent%s de l’archive.', $missing, $missing > 1 ? 's' : '', $missing > 1 ? 's' : '') : null);
        $this->em->flush();

        // Avec une vidéo, l'annonce part une fois la vidéo prête (ProcessVideoHandler, après l'import).
        $hasVideo = array_any($stored, static fn (MediaAttachment $m): bool => $m->isVideo());
        if (!$hasVideo && $post->shouldAnnounce() && !$post->isArchived()) {
            $this->events->dispatch(new PostPublished($post));
        }

        return [$post, $missing];
    }

    private function importStory(InstagramImport $import, ImportItem $item, ArchiveReader $archive): void
    {
        // Une story d'Instagram date d'avant aujourd'hui : elle a expiré et rejoint directement les archives.
        $story = new Story($import->getAccount(), $item->getTakenAt(), '' !== $item->getCaption() ? $item->getCaption() : null, Visibility::Followers);
        try {
            $media = $this->attach($story, $item->getMediaUris()[0], 0, $archive, $item->getExif());
        } catch (MediaProcessingException $e) {
            $item->skip(ItemStatus::Failed, $e->getMessage());

            return;
        }
        if (!$media instanceof MediaAttachment) {
            $item->skip(ItemStatus::Failed, 'Son média n’est pas dans l’archive.');

            return;
        }
        $this->em->persist($story);
        $item->imported($story->getId());
        $this->em->flush();
    }

    /**
     * Extrait un média de l'archive et le confie au traitement habituel des médias.
     *
     * @param array<string, string> $exif
     */
    private function attach(Post|Story $owner, string $uri, int $position, ArchiveReader $archive, array $exif): ?MediaAttachment
    {
        if (!$archive->has($uri)) {
            return null;
        }
        $tmp = $this->filesystem->tempnam(sys_get_temp_dir(), 'socialize-ig-', '.'.pathinfo($uri, \PATHINFO_EXTENSION));
        try {
            $archive->extractTo($uri, $tmp);
            $file = new File($tmp);
            if (!MediaRules::typeOf((string) $file->getMimeType()) instanceof \App\Core\Domain\Media\MediaType) {
                return null;
            }
            $media = $this->ingestor->attach($owner, $file, null, $position);
            $media->addExif($exif);

            return $media;
        } finally {
            $this->filesystem->remove($tmp);
        }
    }
}
