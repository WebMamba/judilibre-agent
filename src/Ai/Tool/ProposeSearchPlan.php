<?php

declare(strict_types=1);

namespace App\Ai\Tool;

use AgUi\Bridge\SymfonyAi\Interrupt\Interrupt;
use AgUi\Bridge\SymfonyAi\Interrupt\ResumableTool;
use AgUi\Bundle\Component\ComponentActivityFactory;
use AgUi\Run\ResumeEntry;
use AgUi\Run\ResumeStatus;
use App\Research\PlannedSearch;
use App\Research\ResearchState;
use App\Research\SearchPlan;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

/**
 * The human in the loop (ADR 0003): the user approves the plan before any search.
 */
#[AsTool(
    name: 'propose_search_plan',
    description: <<<'TXT'
Soumet à l'utilisateur les recherches que vous comptez lancer pour sa question. Il les
approuve, les refuse, ou donne une consigne pour un nouveau plan. Appelez-le directement,
sans demander la permission en texte, au début de chaque nouvelle question de recherche
et après avoir réglé les filtres. Aucune recherche n'est possible sans plan approuvé.
TXT,
)]
final readonly class ProposeSearchPlan implements ResumableTool
{
    public function __construct(
        private ResearchState $state,
        private ComponentActivityFactory $activities,
    ) {
    }

    /**
     * @param list<PlannedSearch> $searches Les recherches prévues, de 1 à 6, chacune avec son but
     */
    public function __invoke(array $searches): Interrupt|string
    {
        if ([] === $searches) {
            return 'Un plan doit contenir au moins une recherche.';
        }

        $props = [
            'searches' => array_map(static fn (PlannedSearch $s): array => (array) $s, $searches),
            'filters' => explode("\n", $this->state->filters->describe()),
        ];

        return Interrupt::confirmation('Approuvez-vous ce plan de recherche ?')
            ->withActivity($this->activities->create('Agent:SearchPlan', $props, "Plan de recherche proposé à l'utilisateur."));
    }

    public function resume(array $arguments, ResumeEntry $resume): string
    {
        $payload = \is_array($resume->payload) ? $resume->payload : [];
        $answered = ResumeStatus::Resolved === $resume->status;

        if ($answered && filter_var($payload['approved'] ?? false, \FILTER_VALIDATE_BOOL)) {
            $this->state->plan = new SearchPlan(array_values(array_map(
                static fn (array $s): PlannedSearch => new PlannedSearch(
                    (string) ($s['query'] ?? ''),
                    (string) ($s['operator'] ?? 'and'),
                    array_values(array_map('strval', (array) ($s['fields'] ?? []))),
                    (string) ($s['purpose'] ?? ''),
                ),
                array_filter((array) ($arguments['searches'] ?? []), 'is_array'),
            )));

            return 'Plan approuvé par l\'utilisateur : lancez les recherches.';
        }

        $feedback = trim((string) ($payload['feedback'] ?? ''));
        if ($answered && '' !== $feedback) {
            return \sprintf("Plan refusé. Consigne de l'utilisateur : « %s ». Proposez un nouveau plan qui en tient compte.", $feedback);
        }

        return "Plan refusé par l'utilisateur : ne lancez aucune recherche, et demandez-lui ce qu'il souhaite.";
    }
}
