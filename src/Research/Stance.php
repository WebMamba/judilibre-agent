<?php

declare(strict_types=1);

namespace App\Research;

/**
 * What a decision, or a principle, does for the side we defend. The triage model picks
 * one of these, or "hors_sujet", for every decision it reads.
 */
enum Stance: string
{
    case Favorable = 'favorable';
    case Defavorable = 'defavorable';
    case Contexte = 'contexte';

    public function label(): string
    {
        return match ($this) {
            self::Favorable => 'Favorable',
            self::Defavorable => 'Défavorable',
            self::Contexte => 'Contexte',
        };
    }
}
