<?php

declare(strict_types=1);

namespace App\Web\Form;

use App\Core\Domain\Post\Visibility;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * Règles du crosspost Instagram : visibilités pour lesquelles « Publier aussi sur Instagram » est coché d'avance.
 *
 * @extends AbstractType<array{visibilities: list<Visibility>, stories: bool}>
 */
final class InstagramRulesType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('visibilities', EnumType::class, [
                'class' => Visibility::class,
                'multiple' => true,
                'expanded' => true,
                'required' => false,
                'label' => 'Cocher « Publier aussi sur Instagram » d’avance pour les publications',
                'choice_label' => static fn (Visibility $v): string => $v->label(),
            ])
            ->add('stories', CheckboxType::class, [
                'label' => 'Appliquer aussi ces règles aux stories',
                'required' => false,
            ]);
    }
}
