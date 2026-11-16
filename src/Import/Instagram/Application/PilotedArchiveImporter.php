<?php

declare(strict_types=1);

namespace App\Import\Instagram\Application;

use App\Core\Application\Social\PilotedActionFailed;
use App\Core\Application\Social\PilotedPublishing;
use App\Core\Application\Social\PilotedRateLimited;
use App\Core\Domain\Post\Visibility;
use App\Import\Instagram\Domain\ImportItem;
use App\Import\Instagram\Domain\ImportStatus;
use App\Import\Instagram\Domain\InstagramImport;
use App\Import\Instagram\Domain\ItemKind;
use App\Import\Instagram\Domain\ItemStatus;
use App\Import\Instagram\Domain\Placement;
use App\Import\Instagram\Infrastructure\ArchiveReader;
use App\Import\Instagram\Infrastructure\Doctrine\ImportItemRepository;
use App\Media\Application\MediaRules;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\File;

/**
 * Import d'une archive Instagram vers un compte Mastodon piloté d'ici : chaque publication part sur son serveur
 * (PilotedPublishing), sans copie ici, une à la fois et à une cadence qui respecte les limites de Mastodon (30 médias
 * par 30 minutes, 300 messages par 3 heures). Un carrousel plus long que la limite du serveur part en fil de messages.
 * La date Instagram est ajoutée au texte : son serveur date chaque message de son envoi.
 */
final readonly class PilotedArchiveImporter
{
    /** Cadence : une minute par média, 36 secondes au moins entre deux messages. */
    public const int SECONDS_PER_MEDIA = 60;
    public const int SECONDS_PER_STATUS = 36;

    public function __construct(
        private EntityManagerInterface $em,
        private ImportItemRepository $items,
        private ArchiveImporter $importer,
        private PilotedPublishing $publishing,
        private Filesystem $filesystem = new Filesystem(),
    ) {
    }

    /**
     * Ligne du journal au lancement : durée estimée de l'envoi.
     *
     * @param list<ImportItem> $posts publications de l'archive
     */
    public static function estimate(array $posts, Placement $whole, \DateTimeImmutable $now, string $domain): string
    {
        $seconds = 0;
        foreach ($posts as $post) {
            if (Placement::Skip !== ($post->getChoice() ?? $whole)) {
                $seconds += self::pause(\count($post->getMediaUris()));
            }
        }
        $end = $now->modify(\sprintf('+%d seconds', $seconds));

        return \sprintf(
            'Envoi vers %s cadencé selon les limites de Mastodon (environ %d médias par heure) · fin estimée le %s à %s',
            $domain, 3600 / self::SECONDS_PER_MEDIA, $end->format('d/m/Y'), $end->format('H:i'),
        );
    }

    /**
     * Envoie l'élément suivant.
     *
     * @return ?int secondes à attendre avant le suivant, null quand l'import est fini
     */
    public function importNext(InstagramImport $import): ?int
    {
        if (ImportStatus::Importing !== $import->getStatus()) {
            return null;
        }
        $next = $this->items->nextPending($import, 1)[0] ?? null;
        if (!$next instanceof ImportItem) {
            $this->importer->finish($import);

            return null;
        }

        $archive = new ArchiveReader(array_column($import->getParts(), 'path'));
        try {
            $pause = $this->send($import, $next, $archive);
        } catch (PilotedRateLimited $e) {
            $import->addLog(\sprintf('… %s · pause : %s', $next->getTakenAt()->format('Y-m-d'), $e->getMessage()));
            $pause = max(60, $e->retryAfter);
        } finally {
            $archive->close();
        }
        $this->em->flush();

        return $pause;
    }

    /**
     * @return int secondes à attendre ensuite
     *
     * @throws PilotedRateLimited
     */
    private function send(InstagramImport $import, ImportItem $item, ArchiveReader $archive): int
    {
        $date = $item->getTakenAt()->format('Y-m-d');
        if ($this->items->alreadyImported($import->getAccount(), $item->getFingerprint())) {
            $item->skip(ItemStatus::Duplicate, 'Déjà importé lors d’un import précédent.');

            return 0;
        }
        if (ItemKind::Story === $item->getKind()) {
            $item->skip(ItemStatus::Skipped, 'Les stories ne sont pas publiées sur Mastodon.');

            return 0;
        }
        $placement = $item->placement();
        if (Placement::Skip === $placement) {
            $item->skip(ItemStatus::Skipped, 'Non importée (choix fait avant l’import).');
            $import->addLog(\sprintf('– %s · publication non importée (votre choix)', $date));

            return 0;
        }

        $account = $import->getAccount();
        $files = [];
        try {
            foreach ($item->getMediaUris() as $uri) {
                $file = $this->extract($uri, $archive);
                if (null !== $file) {
                    $files[] = $file;
                }
            }
            if ([] === $files) {
                $item->skip(ItemStatus::Failed, 'Aucun de ses médias n’est dans l’archive.');
                $import->addLog(\sprintf('! %s · publication sans média dans l’archive : ignorée', $date));

                return 0;
            }

            $chunks = array_chunk($files, $this->publishing->maxMedia($account));
            $visibility = $this->visibility($placement, $import->getPlacement());
            $previous = null;
            try {
                foreach ($chunks as $index => $chunk) {
                    $text = 0 === $index ? $this->text($item, $this->publishing->maxCharacters($account)) : \sprintf('(%d/%d)', $index + 1, \count($chunks));
                    $previous = $this->publishing->publish($account, $chunk, $text, $visibility, $item->getId()->toRfc4122().'-'.$index, $previous);
                }
            } catch (PilotedActionFailed $e) {
                $item->skip(ItemStatus::Failed, $e->getMessage());
                $import->addLog(\sprintf('! %s · échec : %s', $date, $e->getMessage()));

                return self::pause(\count($files));
            }
        } finally {
            foreach ($files as $file) {
                $this->filesystem->remove($file['path']);
            }
        }

        $domain = (string) $account->getDomain();
        $missing = \count($item->getMediaUris()) - \count($files);
        $item->importedElsewhere(\sprintf('Publiée sur %s.', $domain).($missing > 0 ? \sprintf(' %d média%s absent%s de l’archive.', $missing, $missing > 1 ? 's' : '', $missing > 1 ? 's' : '') : ''));
        $import->addLog(\sprintf(
            '✓ %s · « %s » · %d média%s · publiée sur %s',
            $date, mb_strimwidth('' !== $item->getCaption() ? $item->getCaption() : 'sans légende', 0, 40, '…'),
            \count($files), \count($files) > 1 ? 's' : '', $domain,
        ));

        return self::pause(\count($files));
    }

    private static function pause(int $media): int
    {
        return max(self::SECONDS_PER_STATUS, self::SECONDS_PER_MEDIA * $media);
    }

    /**
     * Visibilité sur Mastodon : « Public, sans l'annoncer » devient non listé ; une publication archivée, visible par
     * soi seul (message direct sans destinataire).
     */
    private function visibility(Placement $placement, Placement $whole): Visibility
    {
        return match (true) {
            Placement::Archive === $placement => Visibility::Private,
            Placement::PublicQuiet === $placement => Visibility::Unlisted,
            default => $placement->visibility($whole),
        };
    }

    /**
     * Légende Instagram et sa date d'origine, dans la longueur permise par le serveur.
     */
    private function text(ImportItem $item, int $max): string
    {
        $suffix = 'Publiée sur Instagram le '.$item->getTakenAt()->format('d/m/Y');
        $caption = trim($item->getCaption());
        if ('' === $caption) {
            return $suffix;
        }
        $room = $max - mb_strlen($suffix) - 2;

        return ($room > 0 ? mb_strimwidth($caption, 0, $room, '…') : '')."\n\n".$suffix;
    }

    /**
     * @return ?array{path: string, mimeType: string} fichier extrait de l'archive (null : absent ou format refusé)
     */
    private function extract(string $uri, ArchiveReader $archive): ?array
    {
        if (!$archive->has($uri)) {
            return null;
        }
        $tmp = $this->filesystem->tempnam(sys_get_temp_dir(), 'socialize-ig-', '.'.pathinfo($uri, \PATHINFO_EXTENSION));
        $archive->extractTo($uri, $tmp);
        $type = (string) new File($tmp)->getMimeType();
        if (!MediaRules::typeOf($type) instanceof \App\Core\Domain\Media\MediaType) {
            $this->filesystem->remove($tmp);

            return null;
        }

        return ['path' => $tmp, 'mimeType' => $type];
    }
}
