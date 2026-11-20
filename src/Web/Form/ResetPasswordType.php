<?php

declare(strict_types=1);

namespace App\Web\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Nouveau mot de passe choisi depuis le lien « Mot de passe oublié » : mêmes règles qu'à l'inscription.
 *
 * @extends AbstractType<array{newPassword: string}>
 */
final class ResetPasswordType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('newPassword', RepeatedType::class, [
            'type' => PasswordType::class,
            'first_options' => ['label' => 'Nouveau mot de passe', 'help' => '10 caractères au minimum.', 'attr' => ['autocomplete' => 'new-password', 'autofocus' => true]],
            'second_options' => ['label' => 'Confirmez le nouveau mot de passe', 'attr' => ['autocomplete' => 'new-password']],
            'invalid_message' => 'Les deux mots de passe ne sont pas identiques.',
            'constraints' => [
                new Assert\NotBlank(),
                new Assert\Length(min: 10, max: 4096, minMessage: 'Le mot de passe doit contenir au moins {{ limit }} caractères.'),
                new Assert\NotCompromisedPassword(skipOnError: true),
            ],
        ]);
    }
}
