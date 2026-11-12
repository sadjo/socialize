<?php

declare(strict_types=1);

namespace App\Admin\Infrastructure\Http;

use App\Core\Domain\Account\Account;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Compte créé par l'administrateur : mêmes règles que l'inscription, sauf la longueur minimale du mot de passe
 * (10 caractères), que l'administrateur peut choisir de ne pas imposer.
 *
 * @extends AbstractType<array{email: string, username: string, displayName: ?string, password: string, publisher: bool, instagramImport: bool}>
 */
final class AccountType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class, [
                'label' => 'Adresse e-mail',
                'attr' => ['autocomplete' => 'off', 'maxlength' => 180],
                'constraints' => [new Assert\NotBlank(), new Assert\Email(), new Assert\Length(max: 180)],
            ])
            ->add('username', TextType::class, [
                'label' => 'Nom d’utilisateur',
                'help' => 'Son adresse dans le fédivers. Lettres minuscules, chiffres, points et tirets bas.',
                'attr' => ['autocomplete' => 'off', 'autocapitalize' => 'none', 'spellcheck' => 'false', 'maxlength' => 30],
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Regex(pattern: '/^'.Account::USERNAME_PATTERN.'$/', message: 'Utilisez seulement des lettres minuscules, des chiffres, des points et des tirets bas (30 caractères au plus).'),
                ],
            ])
            ->add('displayName', TextType::class, [
                'label' => 'Nom affiché',
                'required' => false,
                'attr' => ['maxlength' => 100],
                'constraints' => [new Assert\Length(max: 100)],
            ])
            ->add('password', RepeatedType::class, [
                'type' => PasswordType::class,
                'first_options' => ['label' => 'Mot de passe', 'help' => 'Pas de longueur minimale ici (10 caractères conseillés). À transmettre à la personne.', 'attr' => ['autocomplete' => 'new-password']],
                'second_options' => ['label' => 'Confirmez le mot de passe', 'attr' => ['autocomplete' => 'new-password']],
                'invalid_message' => 'Les deux mots de passe ne sont pas identiques.',
                'constraints' => [
                    new Assert\NotBlank(message: 'Saisissez un mot de passe.'),
                    new Assert\Length(max: 4096),
                ],
            ])
            ->add('publisher', CheckboxType::class, [
                'label' => 'Autoriser à publier',
                'help' => 'Publications et stories. Sans ce droit, le compte peut regarder, aimer, commenter et suivre. Modifiable ensuite dans la liste des comptes.',
                'required' => false,
            ])
            ->add('instagramImport', CheckboxType::class, [
                'label' => 'Autoriser l’import Instagram',
                'help' => 'Import de son archive Instagram. Possible seulement avec le droit de publier.',
                'required' => false,
                'constraints' => [new Assert\Callback(static function (bool $import, ExecutionContextInterface $context): void {
                    $form = $context->getRoot();
                    if ($import && $form instanceof FormInterface && true !== $form->get('publisher')->getData()) {
                        $context->addViolation('L’import Instagram suppose le droit de publier : cochez aussi « Autoriser à publier ».');
                    }
                })],
            ]);
    }
}
