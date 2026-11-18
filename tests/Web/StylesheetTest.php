<?php

declare(strict_types=1);

namespace App\Tests\Web;

use PHPUnit\Framework\TestCase;

/**
 * Règles de base de la feuille de styles dont dépend toute la mise en page.
 *
 * Régression de la v0.9.16 : la règle box-sizing avait disparu ; les marges intérieures s'ajoutaient à la taille des
 * éléments, et la visionneuse plein écran coupait le bas des photos.
 */
final class StylesheetTest extends TestCase
{
    public function testEveryElementIsSizedWithItsPaddingAndBorder(): void
    {
        $css = (string) file_get_contents(\dirname(__DIR__, 2).'/assets/styles/app.css');

        self::assertMatchesRegularExpression('/^\*\{box-sizing:border-box\}$/m', $css);
    }
}
