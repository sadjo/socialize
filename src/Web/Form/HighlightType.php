<?php

declare(strict_types=1);

namespace App\Web\Form;

use App\Core\Domain\Story\Highlight;
use App\Media\Application\MediaRules;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Story à la une : titre et image de couverture (photo de l'appareil, d'Immich ou de Synology Photos).
 *
 * @extends AbstractType<array<string, mixed>>
 */
final class HighlightType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label' => 'Titre',
                'attr' => ['maxlength' => Highlight::MAX_TITLE_LENGTH],
                'constraints' => [new Assert\NotBlank(message: 'Donnez un titre à la story à la une.'), new Assert\Length(max: Highlight::MAX_TITLE_LENGTH)],
            ])
            // Photo choisie : « n-0 » pour le fichier de l'appareil, « i-<id> » (Immich) ou « s-<espace>-<id> » (Synology Photos).
            ->add('order', HiddenType::class, [
                'required' => false,
                'attr' => ['data-compose-target' => 'order'],
                'constraints' => [new Assert\Length(max: 200)],
            ])
            ->add('media', FileType::class, [
                'label' => 'Couverture',
                'multiple' => true,
                'required' => false,
                'attr' => ['accept' => implode(',', MediaRules::IMAGE_MIME_TYPES), 'data-compose-target' => 'input', 'class' => 'sr-only'],
                'help' => 'Une photo de l’appareil ou de votre photothèque, recadrée en rond.',
                'constraints' => [
                    new Assert\Count(max: 1, maxMessage: 'Choisissez une seule photo pour la couverture.'),
                    new Assert\All([new Assert\File(maxSize: MediaRules::MAX_IMAGE_SIZE, mimeTypes: MediaRules::IMAGE_MIME_TYPES, mimeTypesMessage: 'Choisissez une photo (JPEG, PNG, WebP, HEIC…).')]),
                ],
            ])
            ->add('removeCover', CheckboxType::class, [
                'label' => 'Revenir à la couverture automatique (la story la plus récente)',
                'required' => false,
            ]);
    }
}
