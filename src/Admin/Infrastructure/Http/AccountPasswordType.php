<?php

declare(strict_types=1);

namespace App\Admin\Infrastructure\Http;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Nouveau mot de passe d'un compte, choisi par l'administrateur : pas de longueur minimale (comme à la création
 * d'un compte par l'administrateur).
 *
 * @extends AbstractType<array{password: string}>
 */
final class AccountPasswordType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('password', RepeatedType::class, [
            'type' => PasswordType::class,
            'first_options' => ['label' => 'Nouveau mot de passe', 'help' => 'Pas de longueur minimale ici (10 caractères conseillés). À transmettre à la personne.', 'attr' => ['autocomplete' => 'new-password', 'autofocus' => true]],
            'second_options' => ['label' => 'Confirmez le mot de passe', 'attr' => ['autocomplete' => 'new-password']],
            'invalid_message' => 'Les deux mots de passe ne sont pas identiques.',
            'constraints' => [
                new Assert\NotBlank(message: 'Saisissez un mot de passe.'),
                new Assert\Length(max: 4096),
            ],
        ]);
    }
}
