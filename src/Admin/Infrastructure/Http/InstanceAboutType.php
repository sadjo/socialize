<?php

declare(strict_types=1);

namespace App\Admin\Infrastructure\Http;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Bloc « Instance » affiché à côté des fils.
 *
 * @extends AbstractType<array{title: string, body: ?string}>
 */
final class InstanceAboutType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label' => 'Titre',
                'attr' => ['maxlength' => 80],
                'constraints' => [new Assert\NotBlank(), new Assert\Length(max: 80)],
            ])
            ->add('body', TextareaType::class, [
                'label' => 'Texte',
                'required' => false,
                'help' => 'En Markdown : **gras**, *italique*, [lien](https://…), listes avec « - », images.',
                'attr' => ['rows' => 10, 'maxlength' => 5000],
                'constraints' => [new Assert\Length(max: 5000)],
            ]);
    }
}
