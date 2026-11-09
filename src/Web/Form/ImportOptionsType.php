<?php

declare(strict_types=1);

namespace App\Web\Form;

use App\Import\Instagram\Domain\Placement;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @extends AbstractType<array{placement: Placement, keepOriginalDates: bool, importStories: bool}>
 */
final class ImportOptionsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('placement', EnumType::class, [
                'class' => Placement::class,
                'choices' => Placement::forWholeImport(),
                'choice_label' => static fn (Placement $p): string => $p->label(),
                'expanded' => true,
                'label' => 'Visibilité de tout l’import',
                'help' => 'S’applique à toutes les publications laissées sur « Visibilité de l’import » ci-dessous.',
                'constraints' => [new Assert\NotNull()],
            ])
            ->add('keepOriginalDates', CheckboxType::class, [
                'label' => 'Conserver les dates d’origine',
                'required' => false,
            ])
            ->add('importStories', CheckboxType::class, [
                'label' => 'Importer les stories (rangées dans vos archives)',
                'required' => false,
            ]);
    }
}
