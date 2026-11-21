<?php

declare(strict_types=1);

namespace App\Ai\Tool;

use AgUi\Bridge\SymfonyAi\Interrupt\Interrupt;
use AgUi\Bridge\SymfonyAi\Interrupt\ResumableTool;
use AgUi\Bundle\Component\ComponentActivityFactory;
use AgUi\Run\ResumeEntry;
use AgUi\Run\ResumeStatus;
use App\Ai\Tool\Input\ProposedAxis;
use App\Research\Axis;
use App\Research\CaseBrief;
use App\Research\Phase;
use App\Research\ResearchState;
use App\Research\SearchPlan;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * The human in the loop of the research (ADR 0003): the user validates the case brief
 * and the axes, after adding, removing or renaming axes, before any search. After a
 * first round of results, the same tool proposes the complementary axes.
 */
#[AsTool(
    name: 'propose_research_plan',
    description: <<<'TXT'
Soumet à l'utilisateur la fiche dossier (faits anonymisés, question de droit, partie
défendue, objectif) et les axes de recherche. Il peut modifier la fiche, ajouter,
supprimer ou renommer des axes, puis valider, ou demander un autre plan. Aucune recherche
n'est possible sans plan validé. Après une première présentation des résultats, si
l'utilisateur veut poursuivre, proposez seulement les axes complémentaires.
La fiche doit être anonymisée : ni nom, ni lieu précis, ni date identifiante.
TXT,
)]
final readonly class ProposeResearchPlan implements ResumableTool
{
    public const int MAX_AXES = 6;

    public function __construct(
        private ResearchState $state,
        private ComponentActivityFactory $activities,
        private ValidatorInterface $validator,
    ) {
    }

    /**
     * @param string             $title Le nom du dossier, court (ex. « Licenciement pendant un arrêt maladie »)
     * @param CaseBrief          $brief La fiche dossier, anonymisée
     * @param list<ProposedAxis> $axes  Les axes de recherche, de 1 à 6
     */
    public function __invoke(string $title, CaseBrief $brief, array $axes): Interrupt|string
    {
        if (null !== $refusal = PhaseGuard::refuse($this->state, [Phase::Qualification, Phase::Plan, Phase::Selection], 'propose_research_plan')) {
            return $refusal;
        }

        $axes = array_values(array_filter($axes, static fn (ProposedAxis $a): bool => '' !== trim($a->title)));
        if ([] === $axes || \count($axes) > self::MAX_AXES) {
            return \sprintf('Un plan compte de 1 à %d axes.', self::MAX_AXES);
        }

        $complementary = Phase::Selection === $this->state->phase;
        if (!$complementary) {
            $this->state->phase = Phase::Plan;
        }

        $props = [
            'title' => $title,
            'brief' => (array) ($complementary && null !== $this->state->brief ? $this->state->brief : $brief),
            'axes' => array_map(static fn (ProposedAxis $a): array => ['title' => $a->title, 'objective' => $a->objective], $axes),
            'previousAxes' => $complementary && null !== $this->state->plan
                ? array_map(static fn (Axis $a): string => $a->title, $this->state->plan->axes)
                : [],
            'complementary' => $complementary,
            'filters' => explode("\n", $this->state->filters->describe()),
        ];

        return Interrupt::confirmation($complementary ? 'Validez-vous ces axes complémentaires ?' : 'Validez-vous la fiche dossier et le plan de recherche ?')
            ->withActivity($this->activities->create('Agent:ResearchPlan', $props, "Fiche dossier et plan de recherche proposés à l'utilisateur."));
    }

    public function resume(array $arguments, ResumeEntry $resume): string
    {
        $payload = \is_array($resume->payload) ? $resume->payload : [];
        $answered = ResumeStatus::Resolved === $resume->status;
        $complementary = Phase::Selection === $this->state->phase;

        if ($answered && filter_var($payload['approved'] ?? false, \FILTER_VALIDATE_BOOL)) {
            $plan = \is_array($payload['plan'] ?? null) ? $payload['plan'] : [];

            return $this->approve(
                \is_array($plan['brief'] ?? null) ? $plan['brief'] : (array) ($arguments['brief'] ?? []),
                \is_array($plan['axes'] ?? null) ? $plan['axes'] : (array) ($arguments['axes'] ?? []),
                (string) ($arguments['title'] ?? ''),
                $complementary,
            );
        }

        $feedback = trim((string) ($payload['feedback'] ?? ''));
        if ($answered && '' !== $feedback) {
            return \sprintf("Plan refusé. Consigne de l'utilisateur : « %s ». Proposez un nouveau plan qui en tient compte.", $feedback);
        }

        if (!$complementary) {
            $this->state->phase = Phase::Qualification;
        }

        return "Plan refusé par l'utilisateur : ne lancez aucune recherche, et demandez-lui ce qu'il souhaite.";
    }

    /**
     * @param array<array-key, mixed> $brief
     * @param array<array-key, mixed> $axes
     */
    private function approve(array $brief, array $axes, string $title, bool $complementary): string
    {
        $caseBrief = $complementary && null !== $this->state->brief ? $this->state->brief : new CaseBrief(
            trim((string) ($brief['facts'] ?? '')),
            trim((string) ($brief['issue'] ?? '')),
            trim((string) ($brief['side'] ?? '')),
            trim((string) ($brief['goal'] ?? '')),
        );
        if (\count($this->validator->validate($caseBrief)) > 0) {
            return 'La fiche validée est incomplète (faits, question de droit et partie défendue sont requis) : proposez à nouveau le plan avec une fiche complète.';
        }

        $plan = $this->state->plan ?? new SearchPlan();
        $round = $plan->round() + 1;
        $added = [];
        foreach ($axes as $axis) {
            if (!\is_array($axis) || '' === trim((string) ($axis['title'] ?? ''))) {
                continue;
            }
            $added[] = $plan->axes[] = new Axis('a'.(\count($plan->axes) + 1), trim((string) $axis['title']), trim((string) ($axis['objective'] ?? '')), $round);
            if (\count($added) >= self::MAX_AXES) {
                break;
            }
        }
        if ([] === $added) {
            return "L'utilisateur a validé un plan sans axe : demandez-lui ce qu'il veut chercher.";
        }

        $this->state->brief = $caseBrief;
        $this->state->plan = $plan;
        $this->state->phase = Phase::Recherche;
        if ('' === $this->state->title && '' !== trim($title)) {
            $this->state->title = trim($title);
        }

        return \sprintf(
            "Plan validé par l'utilisateur (tour %d). Cherchez chaque axe avec search_axis, puis présentez les principes avec present_findings :\n%s",
            $round,
            implode("\n", array_map(static fn (Axis $a): string => \sprintf('- %s : %s%s', $a->id, $a->title, '' === $a->objective ? '' : ' — '.$a->objective), $added)),
        );
    }
}
