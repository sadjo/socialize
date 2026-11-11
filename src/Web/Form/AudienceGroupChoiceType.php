<?php

declare(strict_types=1);

namespace App\Web\Form;

use App\Core\Domain\Post\Visibility;
use App\Core\Domain\Social\AudienceGroup;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Groupes d'abonnés auxquels réserver une publication ou une story (cases à cocher), quand « Groupes » est choisi.
 *
 * @extends AbstractType<list<AudienceGroup>>
 */
final class AudienceGroupChoiceType extends AbstractType
{
    /**
     * Ajoute le champ « audienceGroups » et exige au moins un groupe quand le champ de visibilité vaut « Groupes ».
     *
     * @template TData
     *
     * @param FormBuilderInterface<TData> $builder
     * @param list<AudienceGroup>         $groups
     */
    public static function addTo(FormBuilderInterface $builder, string $visibilityField, array $groups): void
    {
        $builder->add('audienceGroups', self::class, ['groups' => $groups]);
        $builder->addEventListener(FormEvents::POST_SUBMIT, static function (FormEvent $event) use ($visibilityField): void {
            $form = $event->getForm();
            $visibility = $form->get($visibilityField)->getData();
            if (Visibility::Groups === $visibility || Visibility::Groups->value === $visibility) {
                $chosen = $form->get('audienceGroups')->getData();
                if (!\is_array($chosen) || [] === $chosen) {
                    $form->get('audienceGroups')->addError(new FormError('Choisissez au moins un groupe.'));
                }
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('groups');
        $resolver->setAllowedTypes('groups', 'array');
        $resolver->setDefaults([
            'label' => 'Groupes',
            'multiple' => true,
            'expanded' => true,
            'required' => false,
            'choice_value' => static fn (?AudienceGroup $g): ?string => $g?->getPublicId(),
            'choice_label' => static fn (AudienceGroup $g): string => $g->getName(),
        ]);
        $resolver->setNormalizer('choices', static fn (Options $options): array => \is_array($groups = $options['groups']) ? $groups : []);
    }

    public function getParent(): string
    {
        return ChoiceType::class;
    }
}
