<?php

declare(strict_types=1);

namespace App\Web\Form;

use App\Core\Domain\Social\AudienceGroup;
use App\Import\Instagram\Domain\Placement;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @extends AbstractType<array{placement: Placement, defaultGroup: ?string, keepOriginalDates: bool, importStories: bool}>
 */
final class ImportOptionsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var list<AudienceGroup> $groups */
        $groups = $options['groups'];
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
            ->add('defaultGroup', ChoiceType::class, [
                'label' => 'Groupe par défaut',
                'help' => 'Pour les publications « Abonnés seulement » : les réserver à un groupe d’abonnés. Chaque publication peut en choisir un autre ci-dessous.',
                'required' => false,
                'placeholder' => 'Aucun : tous les abonnés',
                'choices' => array_combine(
                    array_map(static fn (AudienceGroup $g): string => $g->getName(), $groups),
                    array_map(static fn (AudienceGroup $g): string => $g->getPublicId(), $groups),
                ),
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

    public function configureOptions(OptionsResolver $resolver): void
    {
        // Groupes d'abonnés du compte (groupe par défaut des publications « Abonnés seulement »).
        $resolver->setDefaults(['groups' => []]);
        $resolver->setAllowedTypes('groups', 'array');
    }
}
