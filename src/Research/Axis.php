<?php

declare(strict_types=1);

namespace App\Research;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * One line of research of the plan, as the user approved it: a title and what it should
 * establish. The agent turns it into Judilibre queries when it searches it.
 */
final class Axis
{
    public function __construct(
        #[Assert\NotBlank]
        public string $id = '',
        #[Assert\NotBlank(normalizer: 'trim')]
        public string $title = '',
        public string $objective = '',
        /** 1 for the first plan, 2 for the first complementary plan… */
        #[Assert\Positive]
        public int $round = 1,
        public bool $searched = false,
    ) {
    }
}
