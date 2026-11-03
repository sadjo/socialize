<?php

declare(strict_types=1);

namespace App\Web\Form;

use App\Core\Domain\Social\Comment;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @extends AbstractType<array{content: string}>
 */
final class CommentType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('content', TextType::class, [
            'label' => 'Commentaire',
            'label_attr' => ['class' => 'sr-only'],
            'attr' => ['placeholder' => 'Ajouter un commentaire…', 'autocomplete' => 'off', 'maxlength' => Comment::MAX_LENGTH],
            'constraints' => [new Assert\NotBlank(message: 'Écrivez un commentaire.'), new Assert\Length(max: Comment::MAX_LENGTH)],
        ]);
    }
}
