<?php

declare(strict_types=1);

namespace App\Triage;

use App\Research\Stance;

/**
 * What the triage model said about one candidate.
 */
final readonly class Verdict
{
    public function __construct(
        public Candidate $candidate,
        /** Null when the candidate is off topic. */
        public ?Stance $stance,
        /** The probability that the candidate is off topic. */
        public float $offTopic,
        /** The probability of the stance chosen among the relevant ones. */
        public float $confidence,
    ) {
    }

    public function retained(): bool
    {
        return null !== $this->stance;
    }
}
