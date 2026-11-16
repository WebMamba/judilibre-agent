<?php

declare(strict_types=1);

namespace App\Research;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * A decision kept in the dossier.
 */
final class PinnedDecision
{
    public function __construct(
        /** The Judilibre id. */
        #[Assert\NotBlank]
        public string $id = '',
        /** How a lawyer cites it, e.g. "Cass. soc., 13 mars 2024, n° 22-18.457". */
        #[Assert\NotBlank]
        public string $title = '',
        /** Why it was kept. */
        public string $note = '',
    ) {
    }
}
