<?php

declare(strict_types=1);

namespace App\Ai\Tool\Input;

use App\Research\FoundDecision;
use Symfony\AI\Platform\Contract\JsonSchema\Attribute\Schema;

/**
 * A principle the agent draws from the decisions the triage kept.
 */
final readonly class ProposedPrinciple
{
    public function __construct(
        /** L'id de l'axe (a1, a2…) auquel le principe répond. */
        public string $axisId = '',
        /** La règle dégagée par la jurisprudence, en une ou deux phrases. */
        public string $statement = '',
        /** Pour la partie défendue : favorable, defavorable ou contexte. */
        #[Schema(enum: ['favorable', 'defavorable', 'contexte'])]
        public string $stance = 'contexte',
        /** @var list<FoundDecision> Les décisions qui posent ou appliquent le principe, les plus fortes d'abord. */
        public array $decisions = [],
    ) {
    }
}
