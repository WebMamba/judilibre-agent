<?php

declare(strict_types=1);

namespace App\Research;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * The searches the user approved for the current question (ADR 0003).
 */
final class SearchPlan
{
    public function __construct(
        /** @var list<PlannedSearch> */
        #[Assert\Count(min: 1)]
        #[Assert\Valid]
        public array $searches = [],
    ) {
    }
}
