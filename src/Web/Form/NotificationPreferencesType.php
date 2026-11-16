<?php

declare(strict_types=1);

namespace App\Web\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * Ce que le compte reçoit en notification sur ses appareils.
 *
 * @extends AbstractType<array{posts: bool, stories: bool, likes: bool, comments: bool}>
 */
final class NotificationPreferencesType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('posts', CheckboxType::class, ['label' => 'Nouvelles publications des comptes que je suis', 'required' => false])
            ->add('stories', CheckboxType::class, ['label' => 'Nouvelles stories des comptes que je suis', 'required' => false])
            ->add('likes', CheckboxType::class, ['label' => 'J’aime sur mes publications', 'required' => false])
            ->add('comments', CheckboxType::class, ['label' => 'Commentaires sur mes publications', 'required' => false]);
    }
}
