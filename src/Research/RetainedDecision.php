<?php

declare(strict_types=1);

namespace App\Research;

/**
 * A decision the triage kept for an axis. Only these may be shown or cited.
 */
final class RetainedDecision
{
    public function __construct(
        public string $id = '',
        public string $axisId = '',
        /** How a lawyer cites it, e.g. "Cour de cassation, soc, 13 mars 2024, n° 22-18.457". */
        public string $citation = '',
        public Stance $stance = Stance::Contexte,
        /** The probability the triage gave to that stance. */
        public float $confidence = 0.0,
        public ?string $summary = null,
        public ?string $date = null,
        public ?string $solution = null,
        /** @var list<string> */
        public array $publication = [],
    ) {
    }
}
