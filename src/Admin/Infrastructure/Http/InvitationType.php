<?php

declare(strict_types=1);

namespace App\Admin\Infrastructure\Http;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Nouvelle invitation : une adresse e-mail (envoi par e-mail), ou rien (lien à copier).
 *
 * @extends AbstractType<array{email: ?string}>
 */
final class InvitationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('email', EmailType::class, [
            'label' => 'Adresse e-mail',
            'required' => false,
            'help' => 'Facultative : sans adresse, vous obtenez un lien à copier et à transmettre vous-même.',
            'attr' => ['placeholder' => 'ami@exemple.fr', 'autocomplete' => 'off', 'maxlength' => 180],
            'constraints' => [new Assert\Email(message: 'Cette adresse e-mail n’est pas valide.'), new Assert\Length(max: 180)],
        ]);
    }
}
