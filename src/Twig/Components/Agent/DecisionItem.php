<?php

declare(strict_types=1);

namespace App\Twig\Components\Agent;

/**
 * One decision the agent shows: only its id and why it matters. Everything else on the
 * card comes from Judilibre (ADR 0001).
 *
 * A class rather than an array shape, so the JSON schema names its properties.
 */
final readonly class DecisionItem
{
    public function __construct(
        /** L'id Judilibre de la décision, tel que search_decisions le donne. */
        public string $id = '',
        /** En une phrase : ce que la décision apporte à la question de l'utilisateur. */
        public string $relevance = '',
    ) {
    }
}
