<?php

declare(strict_types=1);

namespace App\Web\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Connexion à Synology Photos : le mot de passe et le code ouvrent une session sur le NAS et ne sont pas gardés.
 *
 * @extends AbstractType<array{serverUrl: string, userName: string, password: string, otp: ?string}>
 */
final class SynologyPhotosSettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('serverUrl', UrlType::class, [
                'label' => 'Adresse du NAS',
                'default_protocol' => 'https',
                'help' => 'Celle de DSM ou de Synology Photos, joignable depuis ce serveur (pas une adresse QuickConnect).',
                'attr' => ['placeholder' => 'https://photos.exemple.fr', 'autocomplete' => 'url'],
                'constraints' => [new Assert\NotBlank(message: 'Saisissez l’adresse du NAS.'), new Assert\Length(max: 500)],
            ])
            ->add('userName', TextType::class, [
                'label' => 'Identifiant DSM',
                'attr' => ['autocomplete' => 'off', 'autocapitalize' => 'none', 'spellcheck' => 'false'],
                'constraints' => [new Assert\NotBlank(message: 'Saisissez l’identifiant du compte DSM.'), new Assert\Length(max: 200)],
            ])
            ->add('password', PasswordType::class, [
                'label' => 'Mot de passe DSM',
                'help' => 'Utilisé une seule fois pour ouvrir la session, jamais enregistré.',
                'attr' => ['autocomplete' => 'off'],
                'constraints' => [new Assert\NotBlank(message: 'Saisissez le mot de passe du compte DSM.'), new Assert\Length(max: 4096)],
            ])
            ->add('otp', TextType::class, [
                'label' => 'Code à usage unique',
                'required' => false,
                'help' => 'Seulement si ce compte DSM utilise la double authentification.',
                'attr' => ['autocomplete' => 'one-time-code', 'inputmode' => 'numeric', 'maxlength' => 10],
                'constraints' => [new Assert\Length(max: 10)],
            ]);
    }
}
