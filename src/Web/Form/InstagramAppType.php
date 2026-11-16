<?php

declare(strict_types=1);

namespace App\Web\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Application Meta propre au compte (Réglages › Instagram) : identifiant et clé secrète de l'application Instagram.
 * La clé secrète n'est jamais réaffichée ; laissée vide, celle déjà enregistrée est gardée.
 *
 * @extends AbstractType<array{appId: ?string, secret?: ?string}>
 */
final class InstagramAppType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('appId', TextType::class, [
                'label' => 'Identifiant de l’application Instagram',
                'help' => 'Dans l’application Meta : Cas d’utilisation › API Instagram › Configuration de l’API avec connexion Instagram, « ID de l’app Instagram ». Ce n’est pas l’identifiant de l’application affiché en haut du tableau de bord (celui de Facebook), qu’Instagram refuse (« Invalid platform app »).',
                'attr' => ['autocomplete' => 'off', 'inputmode' => 'numeric', 'spellcheck' => 'false'],
                'constraints' => [
                    new Assert\NotBlank(message: 'Indiquez l’identifiant de l’application.'),
                    new Assert\Regex('/^\d{5,32}$/', message: 'L’identifiant de l’application est une suite de chiffres.'),
                ],
            ])
            ->add('secret', PasswordType::class, [
                'label' => 'Clé secrète de l’application Instagram',
                // La clé secrète Instagram (même page), pas celle de l'application Facebook (Paramètres › Général).
                'help' => true === $options['has_secret'] ? 'Enregistrée (chiffrée). Laissez vide pour la garder.' : null,
                'required' => !$options['has_secret'],
                'always_empty' => true,
                'attr' => ['autocomplete' => 'off'],
                'constraints' => [new Assert\Length(max: 128)],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['has_secret' => false]);
        $resolver->setAllowedTypes('has_secret', 'bool');
    }
}
