<?php

declare(strict_types=1);

namespace App\Web\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Musique d'une publication ou d'une story : morceau de la bibliothèque (identifiant public) et
 * début de l'extrait en secondes. Champs cachés remplis par le sélecteur (music-picker).
 *
 * @extends AbstractType<array{track: ?string, start: ?int}>
 */
final class MusicChoiceType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('track', HiddenType::class, [
                'required' => false,
                'attr' => ['data-music-picker-target' => 'track'],
                'constraints' => [new Assert\Length(max: 40), new Assert\Regex('/^[1-9A-HJ-NP-Za-km-z]*$/')],
            ])
            ->add('start', HiddenType::class, [
                'required' => false,
                'attr' => ['data-music-picker-target' => 'start'],
                'constraints' => [new Assert\Regex('/^\d{0,5}$/')],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['label' => false, 'required' => false]);
    }
}
