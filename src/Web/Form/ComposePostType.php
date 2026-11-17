<?php

declare(strict_types=1);

namespace App\Web\Form;

use App\Core\Domain\Post\Post;
use App\Core\Domain\Post\Visibility;
use App\Media\Application\MediaRules;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @extends AbstractType<array<string, mixed>>
 */
final class ComposePostType extends AbstractType
{
    public function __construct(
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            // Ordre des médias : « n-<i> » pour le i-ème fichier de l'appareil, « i-<id> » pour un média Immich, « s-<espace>-<id> » pour Synology Photos.
            ->add('order', HiddenType::class, [
                'required' => false,
                'attr' => ['data-compose-target' => 'order'],
                'constraints' => [new Assert\Length(max: 2000)],
            ])
            ->add('media', FileType::class, [
                'label' => 'Photos et vidéos',
                'multiple' => true,
                'required' => false,
                // Champ masqué : on clique sur la zone de dépôt, qui est son label.
                'attr' => ['accept' => implode(',', MediaRules::acceptedMimeTypes()), 'data-compose-target' => 'input', 'class' => 'sr-only'],
                'constraints' => [
                    new Assert\Count(max: Post::MAX_MEDIA, maxMessage: 'Une publication contient au plus {{ limit }} médias.'),
                    new Assert\All([
                        new Assert\File(
                            maxSize: MediaRules::MAX_VIDEO_SIZE,
                            mimeTypes: MediaRules::acceptedMimeTypes(),
                            mimeTypesMessage: 'Ce format n’est pas accepté. Formats possibles : JPEG, PNG, WebP, HEIC, AVIF, GIF, MP4, MOV, WebM.',
                        ),
                    ]),
                ],
            ])
            ->add('altTexts', CollectionType::class, [
                'label' => false,
                'entry_type' => TextType::class,
                'entry_options' => ['label' => false, 'required' => false, 'constraints' => [new Assert\Length(max: 1500)]],
                'allow_add' => true,
                'required' => false,
            ])
            ->add('caption', TextareaType::class, [
                'label' => 'Légende',
                'required' => false,
                'attr' => ['rows' => 5, 'maxlength' => Post::MAX_CAPTION_LENGTH, 'placeholder' => 'Écrivez une légende… #hashtags, @mentions et ig:@comptes Instagram acceptés', 'data-compose-target' => 'caption', 'data-controller' => 'mention', 'data-mention-url-value' => $this->urls->generate('app_mention_suggestions', []), 'data-mention-instagram-url-value' => $this->urls->generate('app_mention_instagram')],
                'constraints' => [new Assert\Length(max: Post::MAX_CAPTION_LENGTH)],
            ])
            ->add('location', TextType::class, [
                'label' => 'Lieu',
                'required' => false,
                'attr' => ['maxlength' => 255, 'placeholder' => 'Étretat, Normandie'],
                'constraints' => [new Assert\Length(max: 255)],
            ])
            ->add('visibility', EnumType::class, [
                'label' => 'Visibilité',
                'class' => Visibility::class,
                'expanded' => true,
                'choice_label' => static fn (Visibility $v): string => $v->label(),
                'data' => Visibility::Public,
            ])
            ->add('contentWarning', TextType::class, [
                'label' => 'Avertissement de contenu',
                'required' => false,
                'help' => 'Facultatif. Les médias restent floutés jusqu’à ce qu’on clique dessus.',
                'attr' => ['maxlength' => 500, 'placeholder' => 'Exemple : macro d’insecte'],
                'constraints' => [new Assert\Length(max: 500)],
            ])
            ->add('music', MusicChoiceType::class);
        /** @var list<\App\Core\Domain\Social\AudienceGroup> $groups */
        $groups = $options['groups'];
        AudienceGroupChoiceType::addTo($builder, 'visibility', $groups);
        /** @var ?\App\Crosspost\Meta\Domain\InstagramConnection $instagram */
        $instagram = $options['instagram'];
        CrosspostField::addTo($builder, $instagram, story: false);
    }

    public function configureOptions(\Symfony\Component\OptionsResolver\OptionsResolver $resolver): void
    {
        // Groupes d'abonnés du compte (visibilité « Groupes »).
        $resolver->setDefaults(['groups' => []]);
        $resolver->setAllowedTypes('groups', 'array');
        CrosspostField::configure($resolver);
    }
}
