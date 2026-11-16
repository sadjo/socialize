<?php

declare(strict_types=1);

namespace App\Web\Security;

use App\Core\Domain\Account\AccountUser;
use App\Import\Instagram\Domain\InstagramImport;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Seul le titulaire d'un import le voit et le pilote.
 *
 * @extends Voter<string, InstagramImport>
 */
final class ImportVoter extends Voter
{
    public const string MANAGE = 'IMPORT_MANAGE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::MANAGE === $attribute && $subject instanceof InstagramImport;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        return $user instanceof AccountUser && $subject->isOwnedBy($user->getAccount());
    }
}
