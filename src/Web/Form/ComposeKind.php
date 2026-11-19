<?php

declare(strict_types=1);

namespace App\Web\Form;

use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Onglets « Photos et vidéos » / « Texte » d'une nouvelle publication (ici ou sur Mastodon) : champ caché « kind »,
 * libellé et exemple du champ de texte selon l'onglet (compose_controller les échange sans recharger la page).
 */
final class ComposeKind
{
    public const string MEDIA = 'media';
    public const string TEXT = 'text';

    private const string TEXT_LABEL = 'Votre texte';
    private const string TEXT_PLACEHOLDER = 'Écrivez votre publication… #hashtags et @mentions acceptés';

    /**
     * Onglet ouvert d'après l'adresse (?type=texte), photos et vidéos par défaut.
     */
    public static function fromQuery(?string $type): string
    {
        return 'texte' === $type ? self::TEXT : self::MEDIA;
    }

    /**
     * @template TData
     *
     * @param FormBuilderInterface<TData> $builder
     */
    public static function addTo(FormBuilderInterface $builder, string $kind): void
    {
        $builder->add('kind', HiddenType::class, [
            'data' => $kind,
            'attr' => ['data-compose-target' => 'kind'],
            'constraints' => [new Assert\Choice(choices: [self::MEDIA, self::TEXT])],
        ]);
    }

    public static function captionLabel(string $kind, string $mediaLabel): string
    {
        return self::TEXT === $kind ? self::TEXT_LABEL : $mediaLabel;
    }

    /**
     * @return array<string, string|int>
     */
    public static function captionAttr(string $kind, string $mediaLabel, string $mediaPlaceholder): array
    {
        return [
            'rows' => self::TEXT === $kind ? 8 : 5,
            'placeholder' => self::TEXT === $kind ? self::TEXT_PLACEHOLDER : $mediaPlaceholder,
            'data-media-label' => $mediaLabel,
            'data-text-label' => self::TEXT_LABEL,
            'data-media-placeholder' => $mediaPlaceholder,
            'data-text-placeholder' => self::TEXT_PLACEHOLDER,
        ];
    }
}
