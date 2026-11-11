<?php

declare(strict_types=1);

namespace App\Web\Form;

use App\Core\Domain\Account\Account;
use App\Core\Domain\Social\AudienceGroup;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Nom d'un groupe d'abonnés et, à sa modification, ses membres choisis parmi les abonnés.
 *
 * @extends AbstractType<array{name: string, members?: list<string>}>
 */
final class AudienceGroupType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('name', TextType::class, [
            'label' => 'Nom du groupe',
            'attr' => ['maxlength' => AudienceGroup::MAX_NAME_LENGTH, 'placeholder' => 'Famille'],
            'constraints' => [new Assert\NotBlank(message: 'Donnez un nom au groupe.'), new Assert\Length(max: AudienceGroup::MAX_NAME_LENGTH)],
        ]);

        /** @var list<Account>|null $accounts */
        $accounts = $options['accounts'];
        if (null !== $accounts) {
            $choices = [];
            foreach ($accounts as $account) {
                $choices[$account->getAcct()] = $account->getId()->toRfc4122();
            }
            $builder->add('members', ChoiceType::class, [
                'label' => 'Membres',
                'choices' => $choices,
                'multiple' => true,
                'expanded' => true,
                'required' => false,
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        // Comptes proposés comme membres (abonnés et membres actuels) ; null : nom seulement (création).
        $resolver->setDefaults(['accounts' => null]);
        $resolver->setAllowedTypes('accounts', ['null', 'array']);
    }
}
