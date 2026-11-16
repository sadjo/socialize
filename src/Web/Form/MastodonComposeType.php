<?php

declare(strict_types=1);

namespace App\Web\Form;

use App\Integration\Mastodon\Application\MastodonLimits;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Publication sur un compte Mastodon : médias de l'appareil, texte, visibilité et avertissement, dans les limites de
 * son serveur (vérifiées aussi à l'envoi par MastodonPublisher).
 *
 * @extends AbstractType<array<string, mixed>>
 */
final class MastodonComposeType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var MastodonLimits $limits */
        $limits = $options['limits'];
        $builder
            // Ordre des médias : « n-<i> » pour le i-ème fichier choisi (rempli par le contrôleur compose).
            ->add('order', HiddenType::class, [
                'required' => false,
                'attr' => ['data-compose-target' => 'order'],
                'constraints' => [new Assert\Length(max: 2000)],
            ])
            ->add('media', FileType::class, [
                'label' => 'Photos et vidéos',
                'multiple' => true,
                'required' => false,
                'attr' => ['accept' => 'image/*,video/*', 'data-compose-target' => 'input', 'class' => 'sr-only'],
                'constraints' => [new Assert\Count(max: $limits->maxMedia, maxMessage: 'Votre serveur accepte {{ limit }} médias au plus par publication.')],
            ])
            ->add('altTexts', CollectionType::class, [
                'label' => false,
                'entry_type' => TextType::class,
                'entry_options' => ['label' => false, 'required' => false, 'constraints' => [new Assert\Length(max: 1500)]],
                'allow_add' => true,
                'required' => false,
            ])
            ->add('caption', TextareaType::class, [
                'label' => 'Texte',
                'required' => false,
                'attr' => ['rows' => 5, 'maxlength' => $limits->maxCharacters, 'placeholder' => 'Écrivez un texte… #hashtags et @mentions acceptés', 'data-compose-target' => 'caption'],
                'constraints' => [new Assert\Length(max: $limits->maxCharacters)],
            ])
            ->add('visibility', ChoiceType::class, [
                'label' => 'Visibilité',
                'expanded' => true,
                'choices' => ['Public' => 'public', 'Non listé' => 'unlisted', 'Abonnés' => 'private'],
                'data' => 'public',
                'constraints' => [new Assert\Choice(choices: ['public', 'unlisted', 'private'])],
            ])
            ->add('contentWarning', TextType::class, [
                'label' => 'Avertissement de contenu',
                'required' => false,
                'help' => 'Facultatif. Les médias restent floutés jusqu’à ce qu’on clique dessus.',
                'attr' => ['maxlength' => 500, 'placeholder' => 'Exemple : macro d’insecte'],
                'constraints' => [new Assert\Length(max: 500)],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('limits');
        $resolver->setAllowedTypes('limits', MastodonLimits::class);
    }
}
