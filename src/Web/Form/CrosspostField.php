<?php

declare(strict_types=1);

namespace App\Web\Form;

use App\Core\Domain\Post\Visibility;
use App\Crosspost\Meta\Domain\InstagramConnection;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Case « Publier aussi sur Instagram » des écrans de création, si le compte a connecté Instagram. Cochée d'avance
 * selon les règles du compte pour la visibilité par défaut (Public) ; le contrôleur Stimulus « crosspost » la suit
 * quand la visibilité change, tant qu'on ne l'a pas touchée.
 */
final class CrosspostField
{
    public const string NAME = 'crosspostInstagram';

    /**
     * @template TData
     *
     * @param FormBuilderInterface<TData> $builder
     */
    public static function addTo(FormBuilderInterface $builder, ?InstagramConnection $connection, bool $story): void
    {
        if (!$connection instanceof InstagramConnection) {
            return;
        }
        $builder->add(self::NAME, CheckboxType::class, [
            'label' => \sprintf('Publier aussi sur Instagram (@%s)', $connection->getUsername()),
            'required' => false,
            'data' => $connection->checksByDefault(Visibility::Public, $story),
        ]);
    }

    public static function configure(OptionsResolver $resolver): void
    {
        // Compte Instagram relié (ou null : pas de case).
        $resolver->setDefaults(['instagram' => null]);
        $resolver->setAllowedTypes('instagram', ['null', InstagramConnection::class]);
    }

    /**
     * Visibilités pour lesquelles la case est cochée d'avance.
     *
     * @return list<string>
     */
    public static function defaults(?InstagramConnection $connection, bool $story): array
    {
        if (!$connection instanceof InstagramConnection || ($story && !$connection->sharesStories())) {
            return [];
        }

        return array_map(static fn (Visibility $v): string => $v->value, $connection->getAutoVisibilities());
    }
}
