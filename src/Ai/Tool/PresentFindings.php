<?php

declare(strict_types=1);

namespace App\Ai\Tool;

use AgUi\Bridge\SymfonyAi\Interrupt\Interrupt;
use AgUi\Bridge\SymfonyAi\Interrupt\ResumableTool;
use AgUi\Bundle\Component\ComponentActivityFactory;
use AgUi\Run\ResumeEntry;
use AgUi\Run\ResumeStatus;
use App\Ai\Tool\Input\ProposedPrinciple;
use App\Research\FoundDecision;
use App\Research\Phase;
use App\Research\Principle;
use App\Research\ResearchState;
use App\Research\SearchFilters;
use App\Research\Stance;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Shows the principles of a round of research, by axis, each with its decisions, and
 * asks what next (ADR 0004): search more, guided by what the user pinned, or write.
 * Pinning and excluding only change the state the next run carries.
 */
#[AsTool(
    name: 'present_findings',
    description: <<<'TXT'
Présente à l'utilisateur les principes (la jurisprudence) dégagés des décisions retenues
par le tri, regroupés par axe, chacun avec ses décisions (ids retenus par search_axis
uniquement) et une phrase de pertinence. L'utilisateur épingle ou écarte, puis choisit :
poursuivre la recherche ou passer à la rédaction. Appelez-le une fois tous les axes du
tour cherchés, après avoir lu les décisions les plus fortes de chaque principe.
TXT,
)]
final readonly class PresentFindings implements ResumableTool
{
    public function __construct(
        private ResearchState $state,
        private ComponentActivityFactory $activities,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @param list<ProposedPrinciple> $principles Les principes, regroupés par axe, les plus importants d'abord
     * @param string                  $summary    La synthèse du tour de recherche, en deux ou trois phrases
     */
    public function __invoke(array $principles, string $summary = ''): Interrupt|string
    {
        if (null !== $refusal = PhaseGuard::refuse($this->state, [Phase::Recherche], 'present_findings')) {
            return $refusal;
        }
        $pending = $this->state->plan?->pending() ?? [];
        if ([] !== $pending) {
            return 'Axes pas encore cherchés : '.implode(', ', array_map(static fn ($a): string => $a->id, $pending)).'. Cherchez-les avec search_axis avant de présenter les principes.';
        }

        $round = $this->state->plan?->round() ?? 1;
        $kept = [];
        $dropped = [];
        foreach ($principles as $proposed) {
            if ('' === trim($proposed->statement) || null === $this->state->plan?->axis($proposed->axisId)) {
                continue;
            }
            $decisions = [];
            foreach ($proposed->decisions as $decision) {
                $decision = $decision instanceof FoundDecision ? $decision : new FoundDecision(...(array) $decision);
                if (null === $this->state->retainedDecision($decision->id) || \in_array($decision->id, $this->state->excluded, true)) {
                    $dropped[] = $decision->id;
                    continue;
                }
                $decisions[] = $decision;
            }
            if ([] === $decisions) {
                continue;
            }
            $kept[] = $principle = new Principle(
                'p'.(\count($this->state->findings) + 1),
                $proposed->axisId,
                $round,
                trim($proposed->statement),
                Stance::tryFrom($proposed->stance) ?? Stance::Contexte,
                $decisions,
            );
            $this->state->findings[] = $principle;
        }

        if ([] === $kept) {
            return 'Aucun principe présentable : chaque principe doit citer au moins une décision retenue par le tri (ids de search_axis)'.([] === $dropped ? '.' : ', et ces ids ne le sont pas : '.implode(', ', array_unique($dropped)).'.');
        }

        $this->state->phase = Phase::Selection;

        return Interrupt::confirmation('Épinglez ce qui vous intéresse, puis choisissez la suite.')
            ->withActivity($this->activities->create('Agent:Findings', [
                'round' => $round,
                'summary' => $summary,
                'groups' => $this->groups($kept),
                'ignored' => array_values(array_unique($dropped)),
            ], \sprintf('%d principes présentés à l\'utilisateur, avec leurs décisions.', \count($kept))));
    }

    public function resume(array $arguments, ResumeEntry $resume): string
    {
        $payload = \is_array($resume->payload) ? $resume->payload : [];
        if (ResumeStatus::Resolved !== $resume->status) {
            return "L'utilisateur n'a pas encore choisi la suite : attendez son message.";
        }

        $pins = [] === $this->state->dossier ? 'aucune décision épinglée' : \count($this->state->dossier).' décision(s) épinglée(s)';
        $principles = [] === $this->state->pinnedPrinciples ? 'aucun principe épinglé' : 'principes épinglés : '.implode(', ', $this->state->pinnedPrinciples);
        $instruction = trim((string) ($payload['instruction'] ?? ''));

        if ('write' === ($payload['action'] ?? null)) {
            $this->state->phase = Phase::Cadrage;

            return \sprintf(
                "L'utilisateur arrête la recherche et passe à la rédaction (%s ; %s).%s Posez vos questions de cadrage avec ask_questions, puis rédigez la consultation section par section avec write_section.",
                $pins,
                $principles,
                '' === $instruction ? '' : \sprintf(' Sa consigne : « %s ».', $instruction),
            );
        }

        return \sprintf(
            "L'utilisateur veut poursuivre la recherche (%s ; %s).%s Proposez des axes complémentaires avec propose_research_plan, guidés par ce qu'il a épinglé : décisions voisines, textes appliqués, objections à anticiper.",
            $pins,
            $principles,
            '' === $instruction ? '' : \sprintf(' Ce qu\'il veut creuser : « %s ».', $instruction),
        );
    }

    /**
     * @param list<Principle> $principles
     *
     * @return list<array<string, mixed>>
     */
    private function groups(array $principles): array
    {
        $groups = [];
        foreach ($principles as $principle) {
            $axis = $this->state->plan?->axis($principle->axisId);
            $groups[$principle->axisId] ??= ['axis' => ['id' => $principle->axisId, 'title' => $axis?->title ?? $principle->axisId], 'principles' => []];
            $groups[$principle->axisId]['principles'][] = [
                'id' => $principle->id,
                'statement' => $principle->statement,
                'stance' => $principle->stance->value,
                'stanceLabel' => $this->translator->trans('stance.'.$principle->stance->value),
                'decisions' => array_map(function (FoundDecision $found): array {
                    $decision = $this->state->retainedDecision($found->id);

                    return [
                        'id' => $found->id,
                        'relevance' => $found->relevance,
                        'citation' => $decision?->citation ?? $found->id,
                        'stance' => $decision?->stance->value ?? 'contexte',
                        'stanceLabel' => null === $decision ? '' : $this->translator->trans('stance.'.$decision->stance->value),
                        'solution' => null === $decision?->solution ? null : $this->translator->trans($decision->solution, [], 'judilibre'),
                        'summary' => $decision?->summary,
                        'publications' => array_map($this->publication(...), $decision->publication ?? []),
                    ];
                }, $principle->decisions),
            ];
        }

        return array_values($groups);
    }

    /** A code ("b") or, with resolved references, Judilibre's label ("Publié au Bulletin"). */
    private function publication(string $publication): string
    {
        return \in_array($publication, SearchFilters::PUBLICATIONS, true)
            ? $this->translator->trans('publication.'.$publication)
            : $this->translator->trans($publication, [], 'judilibre');
    }
}
