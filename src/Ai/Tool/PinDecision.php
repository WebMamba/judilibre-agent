<?php

declare(strict_types=1);

namespace App\Ai\Tool;

use App\Judilibre\Citation;
use App\Judilibre\DecisionCatalog;
use App\Judilibre\JudilibreException;
use App\Research\ResearchState;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

#[AsTool(
    name: 'pin_decision',
    description: <<<'TXT'
Épingle une décision dans le dossier de l'utilisateur, avec une note sur son intérêt.
Seulement quand l'utilisateur vous le demande : il épingle lui-même depuis les cartes.
TXT,
)]
final readonly class PinDecision
{
    public function __construct(
        private ResearchState $state,
        private DecisionCatalog $catalog,
    ) {
    }

    /**
     * @param string $id   L'id Judilibre de la décision
     * @param string $note Pourquoi la garder, en une phrase
     */
    public function __invoke(string $id, string $note = ''): string
    {
        try {
            $citation = Citation::of($this->catalog->find($id));
        } catch (JudilibreException $e) {
            return \sprintf("Rien n'a été épinglé : la décision %s est introuvable dans Judilibre (%s).", $id, $e->getMessage());
        }

        return $this->state->pin($id, $citation, trim($note))
            ? \sprintf('%s ajoutée au dossier.', $citation)
            : \sprintf('%s est déjà dans le dossier.', $citation);
    }
}
