<?php

declare(strict_types=1);

namespace App\Core\Application\Social;

/**
 * Interaction d'un compte piloté d'ici refusée ou impossible sur son serveur, avec un message à montrer tel quel.
 */
final class PilotedActionFailed extends \DomainException
{
}
