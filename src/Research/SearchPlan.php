<?php

declare(strict_types=1);

namespace App\Research;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * The axes the user approved, round after round (ADR 0003).
 */
final class SearchPlan
{
    public function __construct(
        /** @var list<Axis> */
        #[Assert\Valid]
        public array $axes = [],
    ) {
    }

    public function axis(string $id): ?Axis
    {
        foreach ($this->axes as $axis) {
            if ($axis->id === $id) {
                return $axis;
            }
        }

        return null;
    }

    public function round(): int
    {
        return array_reduce($this->axes, static fn (int $max, Axis $axis): int => max($max, $axis->round), 0);
    }

    /** @return list<Axis> the axes of the current round not searched yet */
    public function pending(): array
    {
        $round = $this->round();

        return array_values(array_filter($this->axes, static fn (Axis $axis): bool => $axis->round === $round && !$axis->searched));
    }
}
