<?php

declare(strict_types=1);

namespace App\Core\Domain\Account;

/**
 * Thème de l'interface choisi par la personne dans ses réglages.
 */
enum Theme: string
{
    /** Clair ou Violet nuit selon le réglage de l'appareil. */
    case Auto = 'auto';
    case Light = 'light';
    /** Sombre, aux couleurs du thème sombre d'Instagram. */
    case Dark = 'dark';
    /** Sombre violet, le thème d'origine de Socialize et le sombre du thème automatique. */
    case Violet = 'violet';

    public function label(): string
    {
        return match ($this) {
            self::Auto => 'Automatique',
            self::Light => 'Clair',
            self::Dark => 'Sombre',
            self::Violet => 'Violet nuit',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Auto => 'Clair ou Violet nuit, comme votre appareil',
            self::Light => 'Fond blanc',
            self::Dark => 'Noir, façon Instagram',
            self::Violet => 'Le sombre d’origine, teinté de violet',
        };
    }
}
