<?php

declare(strict_types=1);

namespace App\Web\Form;

use App\Integration\Mastodon\Application\MastodonRegistration;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\BirthdayType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Création d'un compte sur un serveur Mastodon : les champs que demande son API d'inscription (le serveur vérifie
 * aussi, et ses refus s'affichent sous les champs).
 *
 * @extends AbstractType<array<string, mixed>>
 */
final class MastodonSignupType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var MastodonRegistration $registration */
        $registration = $options['registration'];
        $builder
            ->add('username', TextType::class, [
                'label' => 'Nom d’utilisateur',
                'help' => \sprintf('Votre adresse sera @nom@%s. Lettres, chiffres et « _ ».', $registration->domain),
                'attr' => ['maxlength' => 30, 'autocapitalize' => 'none', 'spellcheck' => 'false', 'autocomplete' => 'username'],
                'constraints' => [new Assert\NotBlank(), new Assert\Regex('/^[A-Za-z0-9_]{1,30}$/', message: 'Lettres, chiffres et « _ » seulement, 30 caractères au plus.')],
            ])
            ->add('email', EmailType::class, [
                'label' => 'Adresse e-mail',
                'help' => \sprintf('%s y enverra un lien pour confirmer votre compte.', $registration->domain),
                'attr' => ['autocomplete' => 'email'],
                'constraints' => [new Assert\NotBlank(), new Assert\Email()],
            ])
            ->add('password', PasswordType::class, [
                'label' => 'Mot de passe',
                'help' => \sprintf('Celui de votre compte %s : 8 caractères au moins.', $registration->domain),
                'attr' => ['autocomplete' => 'new-password', 'minlength' => 8],
                'constraints' => [new Assert\NotBlank(), new Assert\Length(min: 8, max: 72)],
            ]);
        if (null !== $registration->minAge) {
            $builder->add('dateOfBirth', BirthdayType::class, [
                'label' => 'Date de naissance',
                'help' => \sprintf('%s demande d’avoir %d ans au moins.', $registration->domain, $registration->minAge),
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'constraints' => [new Assert\NotBlank()],
            ]);
        }
        if ($registration->approval) {
            $builder->add('reason', TextareaType::class, [
                'label' => 'Pourquoi voulez-vous rejoindre ce serveur ?',
                'help' => 'Son équipe valide chaque inscription.',
                'attr' => ['rows' => 3, 'maxlength' => 500],
                'constraints' => [new Assert\NotBlank(), new Assert\Length(max: 500)],
            ]);
        }
        $builder->add('private', CheckboxType::class, [
            'label' => 'Compte privé',
            'help' => 'Vous validez chaque personne qui veut vous suivre, vos publications sont réservées à vos abonnés par défaut, et votre compte n’est ni suggéré ni cherchable. Décochez pour un compte public.',
            'required' => false,
            'data' => true,
        ]);
        $builder->add('agreement', CheckboxType::class, [
            'label' => \sprintf('J’accepte les règles et les conditions d’utilisation de %s', $registration->domain),
            'constraints' => [new Assert\IsTrue(message: 'Acceptez les règles du serveur pour créer votre compte.')],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('registration');
        $resolver->setAllowedTypes('registration', MastodonRegistration::class);
    }
}
