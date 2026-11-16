<?php

declare(strict_types=1);

namespace App\Ai\Tool;

use App\Judilibre\JudilibreClient;
use App\Judilibre\JudilibreException;
use App\Research\PlannedSearch;
use App\Research\ResearchState;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\AI\Platform\Contract\JsonSchema\Attribute\Schema;

#[AsTool(
    name: 'search_decisions',
    description: <<<'TXT'
Recherche plein texte dans Judilibre. Les filtres en vigueur (juridictions, chambres,
publications, période) s'appliquent toujours : changez-les avec set_filters. Il faut un
plan de recherche approuvé (propose_search_plan). Renvoie 10 décisions par page, avec
un extrait où les termes trouvés sont entre « ».
TXT,
)]
final readonly class SearchDecisions
{
    private const int PAGE_SIZE = 10;
    private const int MAX_RESULTS = 10000;

    public function __construct(
        private JudilibreClient $judilibre,
        private ResearchState $state,
    ) {
    }

    /**
     * @param string       $query    Les termes cherchés, en vocabulaire juridique
     * @param string       $operator "and" : tous les termes ; "or" : l'un d'eux ; "exact" : l'expression exacte
     * @param list<string> $fields   Zones où chercher : expose, moyens, motivations, dispositif, annexes, sommaire, titrage. Vide : toute la décision
     * @param string       $sort     "scorepub" : pertinence, publiées d'abord ; "score" : pertinence ; "date" : les plus récentes d'abord
     * @param int          $page     Page de résultats, à partir de 0
     *
     * @return array<string, mixed>|string
     */
    public function __invoke(
        string $query,
        #[Schema(enum: PlannedSearch::OPERATORS)] string $operator = 'and',
        array $fields = [],
        #[Schema(enum: ['scorepub', 'score', 'date'])] string $sort = 'scorepub',
        #[Schema(minimum: 0)] int $page = 0,
    ): array|string {
        if (null === $this->state->plan) {
            return "Aucun plan de recherche approuvé : proposez-en un avec propose_search_plan et attendez l'accord de l'utilisateur.";
        }
        if (($page + 1) * self::PAGE_SIZE > self::MAX_RESULTS) {
            return 'Judilibre ne donne pas accès au-delà des 10 000 premiers résultats : affinez la requête ou les filtres.';
        }

        try {
            $response = $this->judilibre->search([
                'query' => $query,
                'operator' => $operator,
                'field' => array_values(array_intersect($fields, PlannedSearch::FIELDS)),
                ...$this->state->filters->searchParameters(),
                'sort' => $sort,
                'order' => 'desc',
                'page_size' => self::PAGE_SIZE,
                'page' => $page,
            ]);
        } catch (JudilibreException $e) {
            return 'La recherche Judilibre a échoué : '.$e->getMessage();
        }

        return [
            'total' => $response['total'] ?? 0,
            'page' => $page,
            'more' => null !== ($response['next_page'] ?? null),
            'results' => array_map(self::compact(...), $response['results'] ?? []),
        ];
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return array<string, mixed>
     */
    private static function compact(array $result): array
    {
        $excerpts = [];
        foreach ($result['highlights'] ?? [] as $zone => $highlights) {
            foreach ((array) $highlights as $highlight) {
                $excerpts[] = $zone.': '.str_replace(['<em>', '</em>'], ['«', '»'], (string) $highlight);
            }
        }

        return [
            'id' => $result['id'] ?? null,
            'jurisdiction' => $result['jurisdiction'] ?? null,
            'chamber' => $result['chamber'] ?? null,
            'number' => $result['number'] ?? null,
            'date' => $result['decision_date'] ?? null,
            'solution' => $result['solution'] ?? null,
            'publication' => $result['publication'] ?? [],
            'summary' => $result['summary'] ?? null,
            'themes' => $result['themes'] ?? [],
            'excerpts' => $excerpts,
        ];
    }
}
