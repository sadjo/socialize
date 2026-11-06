<?php

declare(strict_types=1);

namespace App\Web\Form;

use App\Media\Application\MediaRules;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @extends AbstractType<array{displayName: string, bio: ?string, hideNetwork: bool, avatar: mixed}>
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
            ->add('avatar', FileType::class, [
                'label' => 'Photo de profil',
                'required' => false,
                'mapped' => false,
                'attr' => ['accept' => implode(',', MediaRules::IMAGE_MIME_TYPES)],
                'constraints' => [new Assert\File(maxSize: MediaRules::MAX_IMAGE_SIZE, mimeTypes: MediaRules::IMAGE_MIME_TYPES, mimeTypesMessage: 'Choisissez une photo (JPEG, PNG, WebP, HEIC…).')],
            ]);
    }
}
