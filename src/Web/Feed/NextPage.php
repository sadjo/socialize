<?php

declare(strict_types=1);

namespace App\Web\Feed;

use Symfony\Component\HttpFoundation\Request;
use Symfony\UX\Turbo\TurboBundle;

/**
 * Page suivante d'une liste (fil, grille), demandée au défilement par infinite_list_controller : la réponse est alors
 * un flux Turbo (seulement les éléments et le repère de fin), sinon la page complète.
 *
 * Reconnue à l'en-tête propre au contrôleur, pas au seul format demandé : Turbo envoie ses formulaires (connexion,
 * « Suivre », « Accepter ») en acceptant les flux, et le navigateur garde cet en-tête en suivant la redirection vers le
 * fil ou le profil ; la page complète doit alors être rendue, sinon l'écran ne change pas.
 */
final class NextPage
{
    public const string HEADER = 'X-Socialize-Next-Page';

    /**
     * Vrai pour la page suivante d'une liste ; la réponse prend alors le format des flux Turbo.
     */
    public static function requested(Request $request): bool
    {
        if (!$request->headers->has(self::HEADER) || TurboBundle::STREAM_FORMAT !== $request->getPreferredFormat()) {
            return false;
        }
        $request->setRequestFormat(TurboBundle::STREAM_FORMAT);

        return true;
    }
}
