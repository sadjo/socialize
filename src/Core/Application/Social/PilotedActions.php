<?php

declare(strict_types=1);

namespace App\Core\Application\Social;

use App\Core\Domain\Account\Account;
use App\Core\Domain\Post\Post;
use App\Core\Domain\Social\Comment;

/**
 * Interactions d'un compte d'un autre serveur piloté d'ici (connexion Mastodon) : elles passent par l'API de son
 * serveur, qui les envoie ensuite par la fédération. Chaque méthode lève PilotedActionFailed en cas d'échec, avant
 * tout changement ici.
 */
interface PilotedActions
{
    public function like(Account $actor, Post $post): void;

    public function unlike(Account $actor, Post $post): void;

    /**
     * @return string adresse ActivityPub du commentaire publié sur le serveur du compte
     */
    public function comment(Account $actor, Post $post, string $content): string;

    public function deleteComment(Comment $comment): void;

    /**
     * @return bool vrai si l'abonnement est déjà accepté
     */
    public function follow(Account $follower, Account $following): bool;

    public function unfollow(Account $follower, Account $following): void;
}
