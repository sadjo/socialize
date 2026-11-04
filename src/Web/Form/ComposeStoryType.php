<?php

declare(strict_types=1);

namespace App\Web\Form;

use App\Core\Domain\Post\Visibility;
use App\Core\Domain\Story\Highlight;
use App\Core\Domain\Story\Story;
use App\Media\Application\MediaRules;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @extends AbstractType<array<string, mixed>>
 */
final class ComposeStoryType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var list<Highlight> $highlights */
        $highlights = $options['highlights'];
        $choices = ['Ne pas mettre à la une' => ''];
        foreach ($highlights as $highlight) {
            $choices[$highlight->getTitle()] = $highlight->getPublicId();
        }

        $builder
            ->add('media', FileType::class, [
                'label' => 'Photos et vidéos',
                'multiple' => true,
                'attr' => ['accept' => implode(',', MediaRules::acceptedMimeTypes()), 'data-compose-target' => 'input'],
                'help' => \sprintf('Chaque fichier devient une story, dans l’ordre choisi (%d au plus).', Story::MAX_PER_UPLOAD),
                'constraints' => [
                    new Assert\Count(min: 1, max: Story::MAX_PER_UPLOAD, minMessage: 'Ajoutez au moins une photo ou une vidéo.', maxMessage: 'Vous pouvez publier {{ limit }} stories à la fois au plus.'),
                    new Assert\All([new Assert\File(maxSize: MediaRules::MAX_VIDEO_SIZE, mimeTypes: MediaRules::acceptedMimeTypes(), mimeTypesMessage: 'Ce format n’est pas accepté.')]),
                ],
            ])
            ->add('text', TextType::class, [
                'label' => 'Texte sur la story',
                'required' => false,
                'help' => 'Affiché par-dessus la première story.',
                'attr' => ['maxlength' => Story::MAX_TEXT_LENGTH, 'placeholder' => 'Café et brume'],
                'constraints' => [new Assert\Length(max: Story::MAX_TEXT_LENGTH)],
            ])
            ->add('audience', ChoiceType::class, [
                'label' => 'Visible par',
                'expanded' => true,
                'choices' => ['Tout le monde' => Visibility::Public->value, 'Mes abonnés' => Visibility::Followers->value],
                'data' => Visibility::Public->value,
            ])
            ->add('highlight', ChoiceType::class, [
                'label' => 'Mettre à la une',
                'required' => false,
                'choices' => $choices + ['Nouvelle story à la une…' => '__new__'],
                'placeholder' => false,
            ])
            ->add('highlightTitle', TextType::class, [
                'label' => 'Titre de la nouvelle story à la une',
                'required' => false,
                'attr' => ['maxlength' => Highlight::MAX_TITLE_LENGTH, 'placeholder' => 'Montagne'],
                'constraints' => [new Assert\Length(max: Highlight::MAX_TITLE_LENGTH)],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['highlights' => []]);
        $resolver->setAllowedTypes('highlights', 'array');
    }
}
