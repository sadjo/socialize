<?php

declare(strict_types=1);

namespace App\Admin\Infrastructure\Http;

use App\Media\Application\MediaRules;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Photo de profil et image de couverture d'un compte, changées par l'administrateur. Pour un compte Mastodon, elles
 * remplacent ici celles de son serveur ; les retirer fait revenir celles de Mastodon.
 *
 * @extends AbstractType<array{avatar: mixed, removeAvatar: bool, cover: mixed, removeCover: bool}>
 */
final class AccountImagesType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $remote = true === $options['remote'];
        $builder
            ->add('avatar', FileType::class, $this->image($remote ? 'Remplacer la photo Mastodon' : 'Photo de profil'))
            ->add('removeAvatar', CheckboxType::class, [
                'label' => $remote ? 'Rétablir la photo de Mastodon' : 'Retirer la photo de profil',
                'required' => false,
            ])
            ->add('cover', FileType::class, $this->image($remote ? 'Remplacer la couverture Mastodon' : 'Image de couverture'))
            ->add('removeCover', CheckboxType::class, [
                'label' => $remote ? 'Rétablir la couverture de Mastodon' : 'Retirer l’image de couverture',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefault('remote', false);
        $resolver->setAllowedTypes('remote', 'bool');
    }

    /**
     * @return array<string, mixed>
     */
    private function image(string $label): array
    {
        return [
            'label' => $label,
            'required' => false,
            'attr' => ['accept' => implode(',', MediaRules::IMAGE_MIME_TYPES)],
            'constraints' => [new Assert\File(maxSize: MediaRules::MAX_IMAGE_SIZE, mimeTypes: MediaRules::IMAGE_MIME_TYPES, mimeTypesMessage: 'Choisissez une photo (JPEG, PNG, WebP, HEIC…).')],
        ];
    }
}
