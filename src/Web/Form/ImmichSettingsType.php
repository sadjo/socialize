<?php

declare(strict_types=1);

namespace App\Web\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @extends AbstractType<array{serverUrl: string, apiKey: ?string, addToAlbum: bool}>
 */
final class ImmichSettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('serverUrl', UrlType::class, [
                'label' => 'Adresse du serveur',
                'default_protocol' => 'https',
                'attr' => ['placeholder' => 'https://photos.maison.lan', 'autocomplete' => 'url'],
                'constraints' => [new Assert\NotBlank(message: 'Saisissez l’adresse du serveur Immich.'), new Assert\Length(max: 500)],
            ])
            ->add('apiKey', PasswordType::class, [
                'label' => 'Clé API',
                'required' => !$options['connected'],
                'help' => $options['connected'] ? 'Laissez vide pour garder la clé enregistrée.' : 'Immich › Paramètres du compte › Clés API.',
                'attr' => ['autocomplete' => 'off', 'spellcheck' => 'false'],
                'constraints' => [new Assert\Length(max: 200)],
            ])
            ->add('addToAlbum', CheckboxType::class, [
                'label' => 'Ajouter les photos et vidéos publiées à l’album « Publié sur Socialize »',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['connected' => false]);
        $resolver->setAllowedTypes('connected', 'bool');
    }
}
