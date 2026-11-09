<?php

declare(strict_types=1);

namespace App\Import\Instagram\Application;

use App\Import\Instagram\Domain\ImportItem;
use App\Import\Instagram\Domain\InstagramImport;
use App\Import\Instagram\Domain\ItemKind;
use App\Import\Instagram\Domain\ItemStatus;
use App\Import\Instagram\Infrastructure\ArchiveReader;
use App\Import\Instagram\Infrastructure\Doctrine\ImportItemRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Analyse d'une archive : un élément par publication et par story, rien n'est encore importé.
 *
 * Fichiers lus (export JSON d'Instagram, où qu'ils soient dans l'archive) :
 * - posts_1.json, posts_2.json… : publications (carrousels, vidéos) ;
 * - archived_posts.json : publications archivées ;
 * - stories.json : stories ;
 * - posts.json : métadonnées des publications (latitude et longitude), rapprochées par leur date.
 * Les messages privés et le reste de l'archive ne sont jamais lus.
 */
final readonly class ArchiveAnalyzer
{
    /** Écart toléré entre la date d'une publication et celle de ses métadonnées. */
    private const int METADATA_TOLERANCE = 2;

    public function __construct(
        private EntityManagerInterface $em,
        private ImportItemRepository $items,
    ) {
    }

    public function analyze(InstagramImport $import, ArchiveReader $archive): void
    {
        $postFiles = $archive->find('/^posts_\d+\.json$/');
        $archivedFiles = $archive->find('/^archived_posts\.json$/');
        $storyFiles = $archive->find('/^stories\.json$/');
        if ([] === $postFiles && [] === $archivedFiles && [] === $storyFiles) {
            throw new ImportException('Aucune publication ni story trouvée : vérifiez que l’archive a été demandée au format JSON (et non HTML).');
        }

        $locations = $this->locations($archive);
        $state = new AnalysisState($import, $archive);

        foreach ($postFiles as $file) {
            foreach ($this->listOf($archive->json($file), 'ig_posts') as $post) {
                $this->addPost($state, ItemKind::Post, $post, $locations);
            }
        }
        foreach ($archivedFiles as $file) {
            foreach ($this->listOf($archive->json($file), 'ig_archived_post_media') as $post) {
                $this->addPost($state, ItemKind::ArchivedPost, $post, $locations);
            }
        }
        foreach ($storyFiles as $file) {
            foreach ($this->listOf($archive->json($file), 'ig_stories') as $story) {
                $uri = $this->string($story, 'uri');
                if (null !== $uri) {
                    $this->addItem($state, ItemKind::Story, $story, $this->string($story, 'title') ?? '', $this->int($story, 'creation_timestamp'), [$uri], $this->exif($story));
                }
            }
        }

        $missing = $state->missing;
        $repaired = $state->repaired;
        $duplicates = $state->counts['duplicates'];
        $warnings = [];
        if ($missing > 0) {
            $warnings[] = 1 === $missing
                ? '1 média absent de l’archive (supprimé par Instagram) : l’élément concerné sera importé sans.'
                : \sprintf('%d médias absents de l’archive (supprimés par Instagram) : les éléments concernés seront importés sans.', $missing);
        }
        if ($repaired > 0) {
            $warnings[] = 1 === $repaired
                ? '1 légende à l’encodage cassé (« Ã© ») corrigée automatiquement.'
                : \sprintf('%d légendes à l’encodage cassé (« Ã© ») corrigées automatiquement.', $repaired);
        }
        if ($duplicates > 0) {
            $warnings[] = 1 === $duplicates
                ? '1 élément déjà importé : il ne sera pas recréé.'
                : \sprintf('%d éléments déjà importés : ils ne seront pas recréés.', $duplicates);
        }

        $import->analyzed($state->counts, $warnings);
        $this->em->flush();
    }

    /**
     * @param array<mixed>                                  $post
     * @param list<array{0: int, 1: array<string, string>}> $locations
     */
    private function addPost(AnalysisState $state, ItemKind $kind, array $post, array $locations): void
    {
        $media = array_values(array_filter(\is_array($post['media'] ?? null) ? $post['media'] : [], \is_array(...)));
        if ([] === $media) {
            return;
        }
        // Publication à un seul média : la légende et la date sont parfois sur le média.
        $caption = $this->string($post, 'title') ?? $this->string($media[0], 'title') ?? '';
        $timestamp = $this->int($post, 'creation_timestamp') ?: $this->int($media[0], 'creation_timestamp');
        $uris = [];
        foreach ($media as $m) {
            $uri = $this->string($m, 'uri');
            if (null !== $uri) {
                $uris[] = $uri;
            }
        }

        $exif = $this->exif($media[0]);
        foreach ($locations as [$at, $coordinates]) {
            if (abs($at - $timestamp) <= self::METADATA_TOLERANCE) {
                $exif += $coordinates;
                break;
            }
        }
        $this->addItem($state, $kind, $post, $caption, $timestamp, $uris, $exif);
    }

    /**
     * @param array<mixed>          $raw
     * @param list<string>          $uris
     * @param array<string, string> $exif
     */
    private function addItem(AnalysisState $state, ItemKind $kind, array $raw, string $caption, int $timestamp, array $uris, array $exif): void
    {
        if ([] === $uris || $timestamp <= 0) {
            return;
        }
        // L'identifiant Instagram du premier média ne change pas d'un export à l'autre.
        $fingerprint = hash('sha256', 'instagram:'.basename($uris[0]));
        if (isset($state->seen[$fingerprint])) {
            return;
        }
        $state->seen[$fingerprint] = true;

        $fixedCaption = InstagramText::fix($caption);
        if ($fixedCaption !== $caption) {
            ++$state->repaired;
        }
        $present = array_filter($uris, $state->archive->has(...));
        $state->missing += \count($uris) - \count($present);

        $account = $state->import->getAccount();
        $item = new ImportItem($state->import, $account, $kind, $fingerprint, new \DateTimeImmutable('@'.$timestamp), $fixedCaption, $uris, $exif, $raw);
        if ($this->items->alreadyImported($account, $fingerprint)) {
            $item->skip(ItemStatus::Duplicate, 'Déjà importé lors d’un import précédent.');
            ++$state->counts['duplicates'];
        } elseif ([] === $present) {
            $item->skip(ItemStatus::Failed, 'Aucun de ses médias n’est dans l’archive (supprimés par Instagram).');
        }
        $this->em->persist($item);
        ++$state->counts[match ($kind) {
            ItemKind::Post => 'posts',
            ItemKind::ArchivedPost => 'archived',
            ItemKind::Story => 'stories',
        }];
        $state->counts['media'] += \count($uris);
    }

    /**
     * Coordonnées des publications, lues dans leurs métadonnées (posts.json).
     *
     * @return list<array{0: int, 1: array<string, string>}>
     */
    private function locations(ArchiveReader $archive): array
    {
        $locations = [];
        foreach ($archive->find('/^posts\.json$/') as $file) {
            foreach ($this->listOf($archive->json($file), '') as $entry) {
                $values = [];
                foreach (\is_array($entry['label_values'] ?? null) ? $entry['label_values'] : [] as $label) {
                    if (\is_array($label) && \is_string($label['label'] ?? null) && \is_string($label['value'] ?? null)) {
                        $values[mb_strtolower(InstagramText::fix($label['label']))] = $label['value'];
                    }
                }
                $latitude = $values['latitude'] ?? null;
                $longitude = $values['longitude'] ?? null;
                if (is_numeric($latitude) && is_numeric($longitude) && (0.0 !== (float) $latitude || 0.0 !== (float) $longitude)) {
                    $locations[] = [$this->int($entry, 'timestamp'), ['gps-latitude' => $latitude, 'gps-longitude' => $longitude]];
                }
            }
        }

        return $locations;
    }

    /**
     * EXIF fourni par l'archive (Instagram le retire des fichiers) : appareil, objectif, position….
     *
     * @param array<mixed> $media
     *
     * @return array<string, string>
     */
    private function exif(array $media): array
    {
        $exif = [];
        $metadata = \is_array($media['media_metadata'] ?? null) ? $media['media_metadata'] : [];
        foreach (['photo_metadata', 'video_metadata'] as $kind) {
            $entries = \is_array($metadata[$kind] ?? null) ? ($metadata[$kind]['exif_data'] ?? []) : [];
            foreach (\is_array($entries) ? $entries : [] as $entry) {
                foreach (\is_array($entry) ? $entry : [] as $key => $value) {
                    if (!\is_string($key) || !(\is_string($value) || \is_int($value) || \is_float($value)) || \in_array($key, ['device_id', 'source_type', 'camera_position'], true)) {
                        continue;
                    }
                    $name = match ($key) {
                        'latitude' => 'gps-latitude',
                        'longitude' => 'gps-longitude',
                        default => 'instagram-'.$key,
                    };
                    $exif[$name] ??= InstagramText::fix((string) $value);
                }
            }
        }

        return $exif;
    }

    /**
     * Liste d'éléments d'un fichier JSON : directement une liste, ou sous une clé.
     *
     * @return list<array<mixed>>
     */
    private function listOf(mixed $json, string $key): array
    {
        if (\is_array($json) && '' !== $key && \is_array($json[$key] ?? null)) {
            $json = $json[$key];
        }

        return \is_array($json) && array_is_list($json) ? array_values(array_filter($json, \is_array(...))) : [];
    }

    /**
     * @param array<mixed> $data
     */
    private function string(array $data, string $key): ?string
    {
        return \is_string($data[$key] ?? null) && '' !== $data[$key] ? $data[$key] : null;
    }

    /**
     * @param array<mixed> $data
     */
    private function int(array $data, string $key): int
    {
        return \is_int($data[$key] ?? null) ? $data[$key] : 0;
    }
}
