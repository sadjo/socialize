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
 * @extends AbstractType<array{appId: ?string, secret?: ?string, configId?: ?string}>
 */
final class InstagramAppType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('appId', TextType::class, [
                'label' => 'Identifiant de l’application',
                'help' => 'Application récente (connexion unifiée) : l’« ID de l’app » affiché en haut du tableau de bord Meta. Application plus ancienne (connexion Instagram) : l’« ID de l’app Instagram » de la page Configuration de l’API avec connexion Instagram.',
                'attr' => ['autocomplete' => 'off', 'inputmode' => 'numeric', 'spellcheck' => 'false'],
                'constraints' => [
                    new Assert\NotBlank(message: 'Indiquez l’identifiant de l’application.'),
                    new Assert\Regex('/^\d{5,32}$/', message: 'L’identifiant de l’application est une suite de chiffres.'),
                ],
            ])
            ->add('secret', PasswordType::class, [
                'label' => 'Clé secrète de l’application',
                // Connexion unifiée : Paramètres de l'app › Général ; connexion Instagram : clé secrète de l'app Instagram.
                'help' => true === $options['has_secret'] ? 'Enregistrée (chiffrée). Laissez vide pour la garder.' : null,
                'required' => !$options['has_secret'],
                'always_empty' => true,
                'attr' => ['autocomplete' => 'off'],
                'constraints' => [new Assert\Length(max: 128)],
            ])
            ->add('configId', TextType::class, [
                'label' => 'Identifiant de configuration (connexion unifiée)',
                'required' => false,
                'help' => 'Applications récentes seulement : Facebook Login for Business › Configurations, configuration « Jeton d’accès d’utilisateur système », expiration « Jamais », ressource « Compte Instagram », droits instagram_basic et instagram_content_publish (et instagram_manage_contents pour supprimer sur Instagram les publications supprimées ici). Laissez vide pour une application avec connexion Instagram.',
                'attr' => ['autocomplete' => 'off', 'inputmode' => 'numeric', 'spellcheck' => 'false'],
                'constraints' => [new Assert\Regex('/^\d{5,32}$/', message: 'L’identifiant de configuration est une suite de chiffres.')],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['has_secret' => false]);
        $resolver->setAllowedTypes('has_secret', 'bool');
    }
}
