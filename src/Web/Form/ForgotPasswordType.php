<?php

declare(strict_types=1);

namespace App\Web\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * « Mot de passe oublié » : adresse e-mail ou nom d'utilisateur du compte.
 *
 * @extends AbstractType<array{identifier: string}>
 */
final class ForgotPasswordType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('identifier', TextType::class, [
            'label' => 'Adresse e-mail ou nom d’utilisateur',
            'attr' => ['autocomplete' => 'username', 'autocapitalize' => 'none', 'spellcheck' => 'false', 'autofocus' => true],
            'constraints' => [new Assert\NotBlank(message: 'Indiquez votre adresse e-mail ou votre nom d’utilisateur.'), new Assert\Length(max: 180)],
        ]);
    }
}
