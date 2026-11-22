<?php

declare(strict_types=1);

namespace App\Ai\Tool;

use App\Research\Phase;
use App\Research\ResearchState;

/**
 * The server's side of the workflow (ADR 0004): a tool called out of its phases is
 * refused, with what the model should do instead.
 */
final class PhaseGuard
{
    /**
     * @param list<Phase> $allowed
     *
     * @return string|null the refusal, or null when the tool may run
     */
    public static function refuse(ResearchState $state, array $allowed, string $tool): ?string
    {
        if ($state->phase->isOneOf($allowed)) {
            return null;
        }

        return \sprintf(
            '%s est impossible en phase « %s » : il ne sert qu\'en phase %s. %s',
            $tool,
            $state->phase->label(),
            implode(' ou ', array_map(static fn (Phase $p): string => '« '.$p->label().' »', $allowed)),
            self::next($state->phase),
        );
    }

    private static function next(Phase $phase): string
    {
        return match ($phase) {
            Phase::Qualification => 'Qualifiez le dossier (ask_questions), puis proposez la fiche et le plan avec propose_research_plan.',
            Phase::Plan => "Attendez que l'utilisateur valide le plan.",
            Phase::Recherche => 'Cherchez chaque axe du plan avec search_axis, puis présentez les principes avec present_findings.',
            Phase::Selection => "Attendez le choix de l'utilisateur : poursuivre la recherche (propose_research_plan, axes complémentaires) ou passer à la rédaction.",
            Phase::Cadrage => 'Posez vos questions de cadrage (ask_questions), puis rédigez avec write_section.',
            Phase::Redaction, Phase::Livre => 'Rédigez ou retouchez la consultation avec write_section.',
        };
    }
}
