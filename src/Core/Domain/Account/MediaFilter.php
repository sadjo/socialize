<?php

declare(strict_types=1);

namespace App\Core\Domain\Account;

/**
 * Filtre d'affichage qui adoucit et rapproche les médias dans les listes de publications (fils, grilles).
 * Appliqué par le navigateur (CSS) : les fichiers stockés ne changent pas.
 */
enum MediaFilter: string
{
    case Off = 'off';
    case Light = 'light';
    case Medium = 'medium';
    case Strong = 'strong';

    public function label(): string
    {
        return match ($this) {
            self::Off => 'Désactivé',
            self::Light => 'Léger',
            self::Medium => 'Moyen',
            self::Strong => 'Marqué',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Off => 'Couleurs d’origine',
            self::Light => 'À peine plus doux et plus sombre',
            self::Medium => 'Couleurs adoucies, un peu plus sombres',
            self::Strong => 'Teintes douces, sombres et homogènes',
        };
    }
}
