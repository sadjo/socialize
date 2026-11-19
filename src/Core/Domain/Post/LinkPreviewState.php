<?php

declare(strict_types=1);

namespace App\Core\Domain\Post;

/**
 * Aperçu du lien d'une publication texte : en attente de lecture de la page, prêt, ou impossible (page illisible).
 */
enum LinkPreviewState: string
{
    case Pending = 'pending';
    case Ready = 'ready';
    case Failed = 'failed';
}
