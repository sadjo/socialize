<?php

declare(strict_types=1);

namespace App\Web\Form;

use App\Media\Application\MediaRules;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @extends AbstractType<array{displayName: string, bio: ?string, hideNetwork: bool, approveFollowers: bool, avatar: mixed, avatarCrop: ?string, cover: mixed, coverCrop: ?string, removeCover: bool}>
 */
final class ProfileSettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('displayName', TextType::class, [
                'label' => 'Nom affiché',
                'attr' => ['maxlength' => 100],
                'constraints' => [new Assert\NotBlank(), new Assert\Length(max: 100)],
            ])
            ->add('bio', TextareaType::class, [
                'label' => 'Bio',
                'required' => false,
                'attr' => ['rows' => 4, 'maxlength' => 500],
                'constraints' => [new Assert\Length(max: 500)],
            ])
            ->add('hideNetwork', CheckboxType::class, [
                'label' => 'Réserver mes listes d’abonnés et d’abonnements à mes abonnés',
                'required' => false,
            ])
            ->add('approveFollowers', CheckboxType::class, [
                'label' => 'Valider moi-même les demandes d’abonnement',
                'required' => false,
            ])
            ->add('avatar', FileType::class, self::imageOptions('Photo de profil'))
            ->add('avatarCrop', HiddenType::class, self::cropOptions())
            ->add('cover', FileType::class, self::imageOptions('Image de couverture'))
            ->add('coverCrop', HiddenType::class, self::cropOptions())
            ->add('removeCover', CheckboxType::class, [
                'label' => 'Retirer l’image de couverture',
                'required' => false,
                'mapped' => false,
            ]);
    }

    /**
     * Cadre choisi dans l'image (« x,y,largeur,hauteur » en fractions), rempli par le recadrage dans le navigateur.
     *
     * @return array<string, mixed>
     */
    public static function cropOptions(): array
    {
        return ['required' => false, 'mapped' => false, 'constraints' => [new Assert\Length(max: 100)]];
    }

    /**
     * Champ fichier d'une image du profil (non lié aux données : le fichier est traité à part).
     *
     * @return array<string, mixed>
     */
    public static function imageOptions(string $label): array
    {
        return [
            'label' => $label,
            'required' => false,
            'mapped' => false,
            'attr' => ['accept' => implode(',', MediaRules::IMAGE_MIME_TYPES)],
            'constraints' => [new Assert\File(maxSize: MediaRules::MAX_IMAGE_SIZE, mimeTypes: MediaRules::IMAGE_MIME_TYPES, mimeTypesMessage: 'Choisissez une photo (JPEG, PNG, WebP, HEIC…).')],
        ];
    }
}
