<?php

declare(strict_types=1);

namespace App\Core\Domain\Post;

/**
 * Réseau vers lequel une publication ou une story est aussi envoyée (crosspost), à la demande de son auteur.
 */
enum CrosspostTarget: string
{
    case Instagram = 'instagram';
}
