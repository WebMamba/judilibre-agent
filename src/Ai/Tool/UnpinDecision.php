<?php

declare(strict_types=1);

namespace App\Ai\Tool;

use App\Research\ResearchState;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

#[AsTool(
    name: 'unpin_decision',
    description: "Retire une décision du dossier de l'utilisateur, quand il le demande.",
)]
final readonly class UnpinDecision
{
    public function __construct(
        private ResearchState $state,
    ) {
    }

    /**
     * @param string $id L'id Judilibre de la décision
     */
    public function __invoke(string $id): string
    {
        return $this->state->unpin($id)
            ? \sprintf('Décision %s retirée du dossier.', $id)
            : \sprintf("La décision %s n'était pas dans le dossier : rien n'a changé.", $id);
    }
}
