<?php

declare(strict_types=1);

namespace App\Research;

/**
 * A decision under a principle: its id, and what it brings, in one sentence.
 */
final class FoundDecision
{
    public function __construct(
        /** L'id Judilibre d'une décision retenue par le tri. */
        public string $id = '',
        /** En une phrase : ce que cette décision juge, au regard du principe. */
        public string $relevance = '',
    ) {
    }
}
