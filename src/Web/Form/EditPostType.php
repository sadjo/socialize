<?php

declare(strict_types=1);

namespace App\Web\Form;

use App\Core\Domain\Post\Post;
use App\Core\Domain\Post\Visibility;
use App\Media\Application\MediaRules;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Modification d'une publication. Les médias existants sont désignés par leur jeton dans « order ».
 *
 * @extends AbstractType<array<string, mixed>>
 */
final class EditPostType extends AbstractType
{
    public function __construct(
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('media', FileType::class, [
                'label' => 'Ajouter des photos ou des vidéos',
                'multiple' => true,
                'required' => false,
                'attr' => ['accept' => implode(',', MediaRules::acceptedMimeTypes()), 'data-compose-target' => 'input', 'class' => 'sr-only'],
                'constraints' => [
                    new Assert\Count(max: Post::MAX_MEDIA, maxMessage: 'Une publication contient au plus {{ limit }} médias.'),
                    new Assert\All([new Assert\File(maxSize: MediaRules::MAX_VIDEO_SIZE, mimeTypes: MediaRules::acceptedMimeTypes(), mimeTypesMessage: 'Ce format n’est pas accepté.')]),
                ],
            ])
            ->add('altTexts', CollectionType::class, [
                'label' => false,
                'entry_type' => TextType::class,
                'entry_options' => ['label' => false, 'required' => false, 'constraints' => [new Assert\Length(max: 1500)]],
                'allow_add' => true,
                'required' => false,
            ])
            ->add('order', HiddenType::class, [
                'attr' => ['data-compose-target' => 'order'],
                'constraints' => [new Assert\NotBlank(message: 'Une publication garde au moins une photo ou une vidéo.')],
            ])
            ->add('caption', TextareaType::class, [
                'label' => 'Légende',
                'required' => false,
                'attr' => ['rows' => 5, 'maxlength' => Post::MAX_CAPTION_LENGTH, 'data-compose-target' => 'caption', 'data-controller' => 'mention', 'data-mention-url-value' => $this->urls->generate('app_mention_suggestions', []), 'data-mention-instagram-url-value' => $this->urls->generate('app_mention_instagram')],
                'constraints' => [new Assert\Length(max: Post::MAX_CAPTION_LENGTH)],
            ])
            ->add('location', TextType::class, [
                'label' => 'Lieu',
                'required' => false,
                'attr' => ['maxlength' => 255],
                'constraints' => [new Assert\Length(max: 255)],
            ])
            ->add('visibility', EnumType::class, [
                'label' => 'Visibilité',
                'class' => Visibility::class,
                'expanded' => true,
                'choice_label' => static fn (Visibility $v): string => $v->label(),
            ])
            ->add('contentWarning', TextType::class, [
                'label' => 'Avertissement de contenu',
                'required' => false,
                'attr' => ['maxlength' => 500],
                'constraints' => [new Assert\Length(max: 500)],
            ])
            ->add('music', MusicChoiceType::class);
        if (true === $options['can_redate']) {
            // Administrateur : date de publication modifiable (heure de Paris), jamais dans le futur.
            $builder->add('publishedAt', DateTimeType::class, [
                'label' => 'Date de publication',
                'help' => 'Réservé aux administrateurs. Ici, la publication est rangée à cette date. Sur le fédivers, elle est renvoyée avec cette date, mais Mastodon la range au moment où il la reçoit (en tête du profil) : à ne changer qu’au besoin.',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'model_timezone' => 'UTC',
                'view_timezone' => 'Europe/Paris',
                'with_seconds' => false,
                'constraints' => [
                    new Assert\NotNull(message: 'Indiquez une date de publication.'),
                    new Assert\LessThanOrEqual('now', message: 'La date de publication ne peut pas être dans le futur.'),
                ],
            ]);
        }
        /** @var list<\App\Core\Domain\Social\AudienceGroup> $groups */
        $groups = $options['groups'];
        AudienceGroupChoiceType::addTo($builder, 'visibility', $groups);
    }

    public function configureOptions(\Symfony\Component\OptionsResolver\OptionsResolver $resolver): void
    {
        // Groupes d'abonnés du compte (visibilité « Groupes »).
        $resolver->setDefaults(['groups' => [], 'can_redate' => false]);
        $resolver->setAllowedTypes('groups', 'array');
        $resolver->setAllowedTypes('can_redate', 'bool');
    }
}
