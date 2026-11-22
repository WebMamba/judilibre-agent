<?php

declare(strict_types=1);

namespace App\Research;

/**
 * A rule the case law sets ("jurisprudence"), with the decisions that set or apply it.
 */
final class Principle
{
    public function __construct(
        public string $id = '',
        public string $axisId = '',
        public int $round = 1,
        /** The rule, in one or two sentences. */
        public string $statement = '',
        public Stance $stance = Stance::Contexte,
        /** @var list<FoundDecision> */
        public array $decisions = [],
    ) {
    }
}
