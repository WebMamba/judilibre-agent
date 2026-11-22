<?php

declare(strict_types=1);

namespace App\Research;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * The case brief ("fiche dossier"): what the qualification established, without names
 * nor identifying details. It is the only part of the case sent to the triage model.
 */
final class CaseBrief
{
    public function __construct(
        /** Les faits utiles, qualifiés juridiquement, sans nom ni détail identifiant. */
        #[Assert\NotBlank(normalizer: 'trim')]
        public string $facts = '',
        /** La question de droit à trancher. */
        #[Assert\NotBlank(normalizer: 'trim')]
        public string $issue = '',
        /** La partie que nous défendons et sa position (ex. « salarié demandeur »). */
        #[Assert\NotBlank(normalizer: 'trim')]
        public string $side = '',
        /** Ce que le client cherche à obtenir. */
        public string $goal = '',
    ) {
    }

    public function describe(): string
    {
        return implode("\n", array_filter([
            'Faits : '.$this->facts,
            'Question de droit : '.$this->issue,
            'Partie défendue : '.$this->side,
            '' === $this->goal ? null : 'Objectif : '.$this->goal,
        ]));
    }
}
