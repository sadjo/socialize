<?php

declare(strict_types=1);

namespace App\Integration\Mastodon\Application;

use App\Integration\Mastodon\Domain\MastodonPublication;
use App\Integration\Mastodon\Domain\MastodonUser;
use App\Integration\Mastodon\Infrastructure\MastodonClient;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Publication sur un compte Mastodon depuis Socialize : vérifiée selon les limites de son serveur, puis envoyée
 * en tâche de fond (PublishToMastodon). Les fichiers attendent l'envoi dans le stockage partagé avec les workers.
 */
final readonly class MastodonPublisher
{
    public const array VISIBILITIES = ['public', 'unlisted', 'private'];

    public function __construct(
        private MastodonClient $client,
        private EntityManagerInterface $em,
        private MessageBusInterface $bus,
        #[Autowire(service: 'mastodon.cache')]
        private CacheInterface $cache,
        private ClockInterface $clock,
        #[Autowire(param: 'app.mastodon_upload_dir')]
        private string $uploadDir,
        private Filesystem $filesystem = new Filesystem(),
    ) {
    }

    /**
     * Limites du serveur du compte (gardées un jour) ; celles de Mastodon par défaut s'il ne répond pas.
     */
    public function limits(MastodonUser $user): MastodonLimits
    {
        $domain = (string) $user->getAccount()->getDomain();

        return $this->cache->get('mastodon_limits.'.hash('xxh128', $domain), function (ItemInterface $item) use ($domain): MastodonLimits {
            try {
                $item->expiresAfter(86400);

                return $this->client->limits($domain);
            } catch (MastodonException) {
                // Serveur muet : limites de Mastodon par défaut, redemandées dans 5 minutes.
                $item->expiresAfter(300);

                return new MastodonLimits();
            }
        });
    }

    /**
     * @param list<array{file: UploadedFile, alt: ?string}> $media      dans l'ordre choisi
     * @param 'public'|'unlisted'|'private'                 $visibility
     *
     * @throws MastodonException publication refusée par les limites du serveur
     */
    public function publish(MastodonUser $user, array $media, string $text, string $visibility, ?string $spoiler): MastodonPublication
    {
        $domain = (string) $user->getAccount()->getDomain();
        $limits = $this->limits($user);
        $text = trim($text);
        $spoiler = null !== $spoiler && '' !== trim($spoiler) ? trim($spoiler) : null;
        if ([] === $media) {
            throw new MastodonException('Ajoutez au moins une photo ou une vidéo.');
        }
        if (\count($media) > $limits->maxMedia) {
            throw new MastodonException(\sprintf('%s accepte %d médias au plus par publication.', $domain, $limits->maxMedia));
        }
        if (mb_strlen($text) + mb_strlen($spoiler ?? '') > $limits->maxCharacters) {
            throw new MastodonException(\sprintf('%s accepte %d caractères au plus (texte et avertissement).', $domain, $limits->maxCharacters));
        }
        foreach ($media as ['file' => $file]) {
            $type = (string) $file->getMimeType();
            if ((!str_starts_with($type, 'image/') && !str_starts_with($type, 'video/')) || !$limits->accepts($type)) {
                throw new MastodonException(\sprintf('%s n’accepte pas le format de « %s ».', $domain, $file->getClientOriginalName()));
            }
            if ((int) $file->getSize() > $limits->maxSizeFor($type)) {
                throw new MastodonException(\sprintf('« %s » dépasse la taille acceptée par %s (%d Mo).', $file->getClientOriginalName(), $domain, intdiv($limits->maxSizeFor($type), 1024 * 1024)));
            }
        }

        $publication = new MastodonPublication($user, '' !== $text ? $text : ($spoiler ?? ''), \count($media), $this->clock->now());
        $dir = $this->uploadDir.'/'.$publication->getId()->toRfc4122();
        $files = [];
        foreach ($media as $position => ['file' => $file, 'alt' => $alt]) {
            $type = (string) $file->getMimeType();
            $moved = $file->move($dir, \sprintf('%02d.%s', $position, $file->guessExtension() ?? 'bin'));
            $files[] = ['path' => $moved->getPathname(), 'mimeType' => $type, 'alt' => null !== $alt && '' !== trim($alt) ? mb_substr(trim($alt), 0, 1500) : null];
        }
        $this->em->persist($publication);
        $this->em->flush();

        try {
            $this->bus->dispatch(new PublishToMastodon($publication->getId()->toRfc4122(), $files, $text, $visibility, $spoiler));
        } catch (\Throwable $e) {
            $this->filesystem->remove($dir);
            throw $e;
        }

        return $publication;
    }
}
