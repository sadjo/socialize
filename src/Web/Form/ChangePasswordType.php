<?php

declare(strict_types=1);

namespace App\Web\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Security\Core\Validator\Constraints\UserPassword;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Nouveau mot de passe choisi par la personne elle-même : son mot de passe actuel est demandé, et le nouveau suit les
 * règles de l'inscription.
 *
 * @extends AbstractType<array{currentPassword: string, newPassword: string}>
 */
final class ChangePasswordType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('currentPassword', PasswordType::class, [
                'label' => 'Mot de passe actuel',
                'attr' => ['autocomplete' => 'current-password'],
                'constraints' => [
                    new Assert\NotBlank(message: 'Saisissez votre mot de passe actuel.'),
                    new UserPassword(message: 'Ce n’est pas votre mot de passe actuel.'),
                ],
            ])
            ->add('newPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'first_options' => ['label' => 'Nouveau mot de passe', 'help' => '10 caractères au minimum.', 'attr' => ['autocomplete' => 'new-password']],
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
