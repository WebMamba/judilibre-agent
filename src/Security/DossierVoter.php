<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Dossier;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * A dossier is its owner's only: no sharing (ADR 0002).
 *
 * @extends Voter<string, Dossier>
 */
final class DossierVoter extends Voter
{
    public const string OWN = 'DOSSIER_OWN';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::OWN === $attribute && $subject instanceof Dossier;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        return null !== $token->getUser() && $subject->getOwner() === $token->getUser()->getUserIdentifier();
    }
}
