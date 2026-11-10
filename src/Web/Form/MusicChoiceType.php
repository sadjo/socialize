<?php

declare(strict_types=1);

namespace App\Web\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Musique d'une publication ou d'une story : morceau d'Openverse (son identifiant) et
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
                'constraints' => [new Assert\Regex('/^([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})?$/i', message: 'La musique choisie est introuvable. Choisissez-la à nouveau.')],
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
