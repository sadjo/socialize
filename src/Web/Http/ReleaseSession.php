<?php

declare(strict_types=1);

namespace App\Web\Http;

/**
 * Action en lecture seule qui libère la session dès que l'utilisateur est identifié (voir ReleaseSessionListener).
 *
 * La session PHP est verrouillée pendant toute la requête : sans cela, les requêtes simultanées d'un même compte
 * passent l'une après l'autre. Pour les relais d'une photothèque (60 vignettes à la fois, aperçu, vidéo), chaque
 * requête attendrait alors toutes les précédentes et leurs allers-retours vers le serveur de photos.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class ReleaseSession
{
}
