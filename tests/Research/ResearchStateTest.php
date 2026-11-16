<?php

declare(strict_types=1);

namespace App\Tests\Research;

use AgUi\Bridge\SymfonyAi\Exception\InvalidStateException;
use AgUi\Bridge\SymfonyAi\State\SharedState;
use App\Research\PinnedDecision;
use App\Research\PlannedSearch;
use App\Research\ResearchState;
use App\Research\SearchFilters;
use App\Research\SearchPlan;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

final class ResearchStateTest extends TestCase
{
    public function testFiltersStartOnTheCourDeCassation(): void
    {
        $state = new ResearchState();

        self::assertSame(['cc'], $state->filters->jurisdictions);
        self::assertNull($state->plan);
        self::assertSame([], $state->dossier);
    }

    public function testFiltersBecomeJudilibreSearchParameters(): void
    {
        $filters = new SearchFilters(
            jurisdictions: ['cc', 'ca'],
            chambers: ['soc'],
            publications: ['b'],
            dateStart: '2015-01-01',
        );

        self::assertSame([
            'jurisdiction' => ['cc', 'ca'],
            'chamber' => ['soc'],
            'location' => [],
            'publication' => ['b'],
            'date_start' => '2015-01-01',
            'date_end' => null,
        ], $filters->searchParameters());
    }

    public function testADecisionIsPinnedOnce(): void
    {
        $state = new ResearchState();

        self::assertTrue($state->pin('abc', 'Cass. soc., 13 mars 2024, n° 22-18.457', 'Remplacement définitif'));
        self::assertFalse($state->pin('abc', 'Cass. soc., 13 mars 2024, n° 22-18.457'));
        self::assertCount(1, $state->dossier);
        self::assertSame('Remplacement définitif', $state->dossier[0]->note);
    }

    public function testADecisionIsUnpinnedById(): void
    {
        $state = new ResearchState();
        $state->pin('abc', 'A');
        $state->pin('def', 'B');

        self::assertTrue($state->unpin('abc'));
        self::assertFalse($state->unpin('abc'));
        self::assertSame(['def'], array_map(static fn (PinnedDecision $d): string => $d->id, $state->dossier));
    }

    public function testTheModelReadsFiltersPlanAndDossierInPlainFrench(): void
    {
        $state = new ResearchState();
        $state->filters = new SearchFilters(jurisdictions: ['cc'], chambers: ['soc'], publications: ['b'], dateStart: '2015-01-01');
        $state->plan = new SearchPlan([new PlannedSearch('licenciement arrêt maladie', 'and', ['motivations'], 'La règle de principe')]);
        $state->pin('abc', 'Cass. soc., 13 mars 2024, n° 22-18.457', 'Remplacement définitif');

        $description = $state->describeForAgent();

        self::assertStringContainsString('Juridictions : cc', $description);
        self::assertStringContainsString('Chambres : soc', $description);
        self::assertStringContainsString('Publications : b', $description);
        self::assertStringContainsString('Période : du 2015-01-01', $description);
        self::assertStringContainsString('Plan de recherche approuvé', $description);
        self::assertStringContainsString('« licenciement arrêt maladie » (and, champs : motivations) — La règle de principe', $description);
        self::assertStringContainsString('abc — Cass. soc., 13 mars 2024, n° 22-18.457 — Remplacement définitif', $description);
    }

    public function testTheModelIsToldWhenThereIsNoPlanAndAnEmptyDossier(): void
    {
        $description = new ResearchState()->describeForAgent();

        self::assertStringContainsString('Aucun plan de recherche approuvé', $description);
        self::assertStringContainsString('Le dossier est vide', $description);
    }

    public function testResetForgetsEverything(): void
    {
        $state = new ResearchState();
        $state->filters = new SearchFilters(jurisdictions: ['ca']);
        $state->plan = new SearchPlan([new PlannedSearch('bail')]);
        $state->pin('abc', 'A');

        $state->reset();

        self::assertEquals(new ResearchState(), $state);
    }

    public function testTheStateRoundTripsThroughTheAgUiSharedState(): void
    {
        $shared = $this->sharedState($state = new ResearchState());
        $data = [
            'filters' => ['jurisdictions' => ['cc', 'ca'], 'chambers' => ['soc'], 'locations' => [], 'publications' => ['b'], 'dateStart' => '2015-01-01', 'dateEnd' => null],
            'plan' => ['searches' => [['query' => 'arrêt maladie', 'operator' => 'exact', 'fields' => ['motivations'], 'purpose' => 'Principe']]],
            'dossier' => [['id' => 'abc', 'title' => 'A', 'note' => 'n']],
        ];

        $shared->assertValid($data);
        $shared->load($data);

        self::assertSame(['cc', 'ca'], $state->filters->jurisdictions);
        self::assertInstanceOf(PlannedSearch::class, $state->plan?->searches[0]);
        self::assertSame('exact', $state->plan->searches[0]->operator);
        self::assertInstanceOf(PinnedDecision::class, $state->dossier[0]);
        self::assertSame($data, $shared->snapshot());
    }

    /**
     * @param array<string, mixed> $data
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidStates')]
    public function testAStateThatBreaksTheConstraintsIsRejected(array $data): void
    {
        $this->expectException(InvalidStateException::class);

        $this->sharedState(new ResearchState())->assertValid($data);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidStates(): iterable
    {
        yield 'unknown jurisdiction' => [['filters' => ['jurisdictions' => ['cour-supreme']]]];
        yield 'no jurisdiction' => [['filters' => ['jurisdictions' => []]]];
        yield 'unknown publication' => [['filters' => ['publications' => ['z']]]];
        yield 'not a date' => [['filters' => ['dateStart' => '13/03/2024']]];
        yield 'unknown operator' => [['plan' => ['searches' => [['query' => 'bail', 'operator' => 'xor']]]]];
        yield 'blank query' => [['plan' => ['searches' => [['query' => ' ']]]]];
        yield 'pinned without id' => [['dossier' => [['id' => '', 'title' => 'A']]]];
    }

    private function sharedState(ResearchState $state): SharedState
    {
        return new SharedState($state, validator: Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator());
    }
}
