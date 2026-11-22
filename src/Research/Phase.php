<?php

declare(strict_types=1);

namespace App\Research;

/**
 * Where a dossier stands in the consultation workflow (ADR 0004). Each tool is allowed
 * in some phases only: the server enforces the order, not just the prompt.
 */
enum Phase: string
{
    /** The agent asks about the context, the problem and the side defended. */
    case Qualification = 'qualification';
    /** The case brief and the axes wait for the user's approval. */
    case Plan = 'plan';
    /** The approved axes are being searched, and the results triaged. */
    case Recherche = 'recherche';
    /** The user reads the principles, pins, and chooses: search more, or write. */
    case Selection = 'selection';
    /** The agent asks how the consultation should be written. */
    case Cadrage = 'cadrage';
    /** The consultation is being written, section by section. */
    case Redaction = 'redaction';
    /** The consultation is written; the user may still have sections rewritten. */
    case Livre = 'livre';

    public function label(): string
    {
        return match ($this) {
            self::Qualification => 'Qualification',
            self::Plan => 'Plan',
            self::Recherche => 'Recherche',
            self::Selection => 'Sélection',
            self::Cadrage => 'Cadrage',
            self::Redaction => 'Rédaction',
            self::Livre => 'Livré',
        };
    }

    /** @param list<self> $phases */
    public function isOneOf(array $phases): bool
    {
        return \in_array($this, $phases, true);
    }
}
