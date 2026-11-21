<?php

declare(strict_types=1);

namespace App\Ai\Tool;

use App\Ai\Tool\Input\AxisQuery;
use App\Judilibre\Citation;
use App\Judilibre\DecisionCatalog;
use App\Judilibre\JudilibreClient;
use App\Judilibre\JudilibreException;
use App\Research\Phase;
use App\Research\ResearchState;
use App\Research\RetainedDecision;
use App\Triage\Candidate;
use App\Triage\Triage;
use App\Triage\Verdict;
use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

/**
 * Searches one axis of the approved plan, and has the triage model sort what it found
 * (ADR 0004): the agent only reads the decisions kept, never the raw results.
 *
 * Up to 3 queries of 50 results, merged; the decisions already triaged in this dossier,
 * or excluded by the user, are left out. The triage reads what the search gives
 * (summary, themes, highlighted excerpts); for a decision without a summary, it reads
 * the reasons, fetched in parallel.
 */
#[AsTool(
    name: 'search_axis',
    description: <<<'TXT'
Cherche un axe du plan validé dans Judilibre, avec 1 à 3 requêtes de votre choix (50
résultats chacune, filtres en vigueur appliqués), puis fait trier les décisions trouvées
par le modèle de tri : vous ne recevez que les décisions retenues, chacune avec son sens
(favorable, défavorable, contexte) pour la partie défendue. Variez les requêtes : termes
des juges, synonymes, articles de loi. Les décisions déjà triées ne reviennent pas.
TXT,
)]
final readonly class SearchAxis
{
    public const int PAGE_SIZE = 50;
    public const int MAX_QUERIES = 3;

    public function __construct(
        private JudilibreClient $judilibre,
        private DecisionCatalog $catalog,
        private Triage $triage,
        private ResearchState $state,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param string          $axisId  L'id de l'axe à chercher (a1, a2…)
     * @param list<AxisQuery> $queries Les requêtes Judilibre, de 1 à 3
     *
     * @return array<string, mixed>|string
     */
    public function __invoke(string $axisId, array $queries): array|string
    {
        if (null !== $refusal = PhaseGuard::refuse($this->state, [Phase::Recherche], 'search_axis')) {
            return $refusal;
        }
        $axis = $this->state->plan?->axis($axisId);
        if (null === $axis) {
            return \sprintf("L'axe %s n'est pas dans le plan validé.", $axisId);
        }
        if ($axis->searched) {
            return \sprintf("L'axe %s a déjà été cherché : passez au suivant, ou présentez les principes avec present_findings.", $axisId);
        }
        $queries = array_slice(array_values(array_filter($queries, static fn (AxisQuery $q): bool => '' !== trim($q->query))), 0, self::MAX_QUERIES);
        if ([] === $queries) {
            return 'Donnez au moins une requête.';
        }

        $skip = array_flip([...$this->state->seen, ...$this->state->excluded]);
        $found = [];
        $report = [];
        foreach ($queries as $query) {
            try {
                $response = $this->judilibre->search([
                    'query' => $query->query,
                    'operator' => \in_array($query->operator, AxisQuery::OPERATORS, true) ? $query->operator : 'and',
                    'field' => array_values(array_intersect($query->fields, AxisQuery::FIELDS)),
                    ...$this->state->filters->searchParameters(),
                    'sort' => 'scorepub',
                    'order' => 'desc',
                    'page_size' => self::PAGE_SIZE,
                    'page' => 0,
                    // Names, not keys: "Cour de cassation, Chambre sociale" for the citation.
                    'resolve_references' => true,
                ]);
            } catch (JudilibreException $e) {
                $report[] = ['query' => $query->query, 'error' => $e->getMessage()];
                continue;
            }

            $report[] = ['query' => $query->query, 'total' => $response['total'] ?? 0];
            foreach ($response['results'] ?? [] as $result) {
                $id = (string) ($result['id'] ?? '');
                if ('' !== $id && !isset($skip[$id]) && !isset($found[$id])) {
                    $found[$id] = $result;
                }
            }
        }

        if ([] === $found) {
            $axis->searched = true;

            return ['axis' => $axisId, 'queries' => $report, 'candidates' => 0, 'retained' => [], 'pending_axes' => $this->pendingAxes()];
        }

        $candidates = $this->candidates($found);
        try {
            $verdicts = $this->triage->sort($this->context($axis->title, $axis->objective), $candidates);
        } catch (\Throwable $e) {
            $this->logger->error('Triage failed: {message}', ['message' => $e->getMessage(), 'exception' => $e]);

            return 'Le tri des décisions a échoué ('.$e->getMessage().') : réessayez cet axe un peu plus tard, ou dites-le à l\'utilisateur.';
        }

        $retained = [];
        foreach ($verdicts as $verdict) {
            $this->state->seen[] = $verdict->candidate->id;
            if (!$verdict->retained()) {
                continue;
            }
            $decision = new RetainedDecision(
                $verdict->candidate->id,
                $axisId,
                $verdict->candidate->citation,
                $verdict->stance,
                $verdict->confidence,
                $verdict->candidate->summary,
                $verdict->candidate->date,
                $verdict->candidate->solution,
                $verdict->candidate->publication,
            );
            $this->state->retained[] = $decision;
            $retained[] = $this->forModel($decision, $found[$decision->id] ?? [], $verdict);
        }
        $axis->searched = true;

        return [
            'axis' => $axisId,
            'queries' => $report,
            'candidates' => \count($candidates),
            'rejected_by_triage' => \count($candidates) - \count($retained),
            'retained' => $retained,
            'pending_axes' => $this->pendingAxes(),
        ];
    }

    private function context(string $axisTitle, string $objective): string
    {
        return "Dossier (anonymisé) :\n".($this->state->brief?->describe() ?? '')
            ."\n\nAxe de recherche : ".$axisTitle.('' === $objective ? '' : "\nObjectif de l'axe : ".$objective);
    }

    /**
     * @param array<string, array<string, mixed>> $found
     *
     * @return list<Candidate>
     */
    private function candidates(array $found): array
    {
        $withoutSummary = array_keys(array_filter($found, static fn (array $r): bool => '' === trim((string) ($r['summary'] ?? ''))));
        $texts = [] === $withoutSummary ? [] : $this->catalog->findMany($withoutSummary);

        $candidates = [];
        foreach ($found as $id => $result) {
            $lines = [];
            if ('' !== $summary = trim((string) ($result['summary'] ?? ''))) {
                $lines[] = 'Sommaire : '.$summary;
            }
            if ([] !== ($result['themes'] ?? [])) {
                $lines[] = 'Thèmes : '.implode(' ; ', array_map('strval', (array) $result['themes']));
            }
            foreach (self::excerpts($result) as $excerpt) {
                $lines[] = 'Extrait : '.$excerpt;
            }
            if (isset($texts[$id])) {
                $lines[] = 'Motivations : '.self::zone($texts[$id], 'motivations');
            }
            if (\is_string($result['solution'] ?? null)) {
                $lines[] = 'Solution : '.$result['solution'];
            }

            $candidates[] = new Candidate(
                $id,
                Citation::of($result),
                implode("\n", $lines),
                '' === $summary ? null : $summary,
                \is_string($result['decision_date'] ?? null) ? $result['decision_date'] : null,
                \is_string($result['solution'] ?? null) ? $result['solution'] : null,
                array_values(array_map('strval', (array) ($result['publication'] ?? []))),
            );
        }

        return $candidates;
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return array<string, mixed>
     */
    private function forModel(RetainedDecision $decision, array $result, Verdict $verdict): array
    {
        return [
            'id' => $decision->id,
            'citation' => $decision->citation,
            'stance' => $decision->stance->value,
            'confidence' => $verdict->confidence,
            'publication' => $decision->publication,
            'solution' => $decision->solution,
            'summary' => $decision->summary,
            'excerpts' => \array_slice(self::excerpts($result), 0, 2),
        ];
    }

    /** @return list<string> */
    private function pendingAxes(): array
    {
        return array_map(static fn ($a): string => $a->id, $this->state->plan?->pending() ?? []);
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return list<string>
     */
    private static function excerpts(array $result): array
    {
        $excerpts = [];
        foreach ((array) ($result['highlights'] ?? []) as $zone => $highlights) {
            foreach ((array) $highlights as $highlight) {
                $excerpts[] = $zone.': '.str_replace(['<em>', '</em>'], ['«', '»'], (string) $highlight);
            }
        }

        return $excerpts;
    }

    /** @param array<string, mixed> $decision */
    private static function zone(array $decision, string $zone): string
    {
        $text = (string) ($decision['text'] ?? '');
        $segments = $decision['zones'][$zone] ?? [];
        if ([] === $segments) {
            return mb_substr($text, 0, 4000);
        }

        return trim(implode("\n", array_map(
            static fn (array $s): string => mb_substr($text, (int) $s['start'], (int) $s['end'] - (int) $s['start']),
            $segments,
        )));
    }
}
