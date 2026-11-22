<?php

declare(strict_types=1);

namespace App\Triage;

/**
 * Sorts the decisions a search found: useful for our case (and how), or off topic.
 */
interface Triage
{
    /**
     * @param string          $context    the case brief and the axis, anonymized
     * @param list<Candidate> $candidates
     *
     * @return list<Verdict> one per candidate, in the same order
     */
    public function sort(string $context, array $candidates): array;
}
