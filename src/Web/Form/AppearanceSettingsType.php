<?php

declare(strict_types=1);

namespace App\Web\Form;

use App\Core\Domain\Account\MediaFilter;
use App\Core\Domain\Account\Theme;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * Apparence de l'interface pour la personne connectée.
 *
 * @extends AbstractType<array{theme: Theme, mediaFilter: MediaFilter}>
 */
final class AppearanceSettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('theme', EnumType::class, [
            'class' => Theme::class,
            'label' => 'Thème',
            'expanded' => true,
            'choice_label' => static fn (Theme $theme): string => $theme->label(),
        ]);
        $builder->add('mediaFilter', EnumType::class, [
            'class' => MediaFilter::class,
            'label' => 'Filtre des médias',
            'expanded' => true,
            'choice_label' => static fn (MediaFilter $filter): string => $filter->label(),
        ]);
    }
}
