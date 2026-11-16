<?php

declare(strict_types=1);

namespace App\Tests\Ai\Tool;

use App\Ai\Tool\SearchDecisions;
use App\Research\PlannedSearch;
use App\Research\ResearchState;
use App\Research\SearchFilters;
use App\Research\SearchPlan;
use App\Tests\Support\FakeJudilibre;
use PHPUnit\Framework\TestCase;

final class SearchDecisionsTest extends TestCase
{
    public function testItRefusesToSearchWithoutAnApprovedPlan(): void
    {
        $judilibre = new FakeJudilibre();
        $tool = new SearchDecisions($judilibre->client(), new ResearchState());

        $result = $tool('licenciement');

        self::assertIsString($result);
        self::assertStringContainsString('propose_search_plan', $result);
        self::assertSame([], $judilibre->requests);
    }

    public function testTheFiltersOfTheStateAreAlwaysApplied(): void
    {
        $judilibre = new FakeJudilibre();
        $state = $this->approvedState();
        $state->filters = new SearchFilters(jurisdictions: ['cc'], chambers: ['soc'], publications: ['b'], dateStart: '2015-01-01');

        new SearchDecisions($judilibre->client(), $state)('licenciement arrêt maladie', 'and', ['motivations'], 'date', 1);

        self::assertSame([
            'query' => 'licenciement arrêt maladie',
            'operator' => 'and',
            'field' => ['motivations'],
            'jurisdiction' => ['cc'],
            'chamber' => ['soc'],
            'publication' => ['b'],
            'date_start' => '2015-01-01',
            'sort' => 'date',
            'order' => 'desc',
            'page_size' => '10',
            'page' => '1',
        ], $judilibre->query());
    }

    public function testTheModelGetsCompactResultsItCanCite(): void
    {
        $result = new SearchDecisions(new FakeJudilibre()->client(), $this->approvedState())('licenciement');

        self::assertIsArray($result);
        self::assertSame(2, $result['total']);
        self::assertSame(0, $result['page']);
        self::assertFalse($result['more']);
        self::assertSame([
            'id' => '65f1c0a2b4e3d2001a7c9e11',
            'jurisdiction' => 'cc',
            'chamber' => 'soc',
            'number' => '22-18.457',
            'date' => '2024-03-13',
            'solution' => 'cassation',
            'publication' => ['b'],
            'summary' => 'Le licenciement motivé par la désorganisation de l\'entreprise suppose le remplacement définitif du salarié absent.',
            'themes' => ['Contrat de travail, rupture'],
            'excerpts' => ['motivations: Le «licenciement» d\'un salarié en «arrêt maladie» ne peut être justifié que par…'],
        ], $result['results'][0]);
        self::assertNull($result['results'][1]['summary']);
    }

    public function testAJudilibreFailureIsToldToTheModel(): void
    {
        $judilibre = new FakeJudilibre()->respond('search', FakeJudilibre::json('{"message":"Trop de requêtes."}', 429));

        $result = new SearchDecisions($judilibre->client(), $this->approvedState())('licenciement');

        self::assertIsString($result);
        self::assertStringContainsString('Judilibre', $result);
        self::assertStringContainsString('429', $result);
    }

    public function testPagesBeyondTenThousandResultsAreRefused(): void
    {
        $judilibre = new FakeJudilibre();

        $result = new SearchDecisions($judilibre->client(), $this->approvedState())('bail', page: 1000);

        self::assertIsString($result);
        self::assertStringContainsString('10 000', $result);
        self::assertSame([], $judilibre->requests);
    }

    private function approvedState(): ResearchState
    {
        $state = new ResearchState();
        $state->plan = new SearchPlan([new PlannedSearch('licenciement arrêt maladie')]);

        return $state;
    }
}
