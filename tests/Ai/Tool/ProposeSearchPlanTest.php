<?php

declare(strict_types=1);

namespace App\Tests\Ai\Tool;

use AgUi\Bridge\SymfonyAi\Interrupt\Interrupt;
use AgUi\Run\ResumeEntry;
use AgUi\Run\ResumeStatus;
use App\Ai\Tool\ProposeSearchPlan;
use App\Research\PlannedSearch;
use App\Research\ResearchState;
use App\Research\SearchFilters;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ProposeSearchPlanTest extends KernelTestCase
{
    private const array ARGUMENTS = ['searches' => [
        ['query' => 'licenciement arrêt maladie', 'operator' => 'and', 'fields' => ['motivations'], 'purpose' => 'La règle de principe'],
        ['query' => 'remplacement définitif', 'operator' => 'exact', 'fields' => [], 'purpose' => ''],
    ]];

    public function testThePlanIsShownToTheUserWhoMustAnswer(): void
    {
        $state = self::state();
        $state->filters = new SearchFilters(jurisdictions: ['cc'], chambers: ['soc'], publications: ['b']);

        $interrupt = self::tool()([
            new PlannedSearch('licenciement arrêt maladie', 'and', ['motivations'], 'La règle de principe'),
            new PlannedSearch('remplacement définitif', 'exact'),
        ]);

        self::assertInstanceOf(Interrupt::class, $interrupt);
        self::assertSame(Interrupt::CONFIRMATION, $interrupt->reason);
        $html = $interrupt->activity?->content['html'] ?? '';
        self::assertStringContainsString('licenciement arrêt maladie', $html);
        self::assertStringContainsString('La règle de principe', $html);
        self::assertStringContainsString('remplacement définitif', $html);
        self::assertStringContainsString('Chambres : soc', $html);
        self::assertStringContainsString('data-ag-ui-approved-param="true"', $html);
        self::assertStringContainsString('data-controller="search-plan"', $html);
        self::assertSame([], $state->dossier, 'Proposing changes nothing.');
        self::assertNull($state->plan);
    }

    public function testAnEmptyPlanIsRefused(): void
    {
        self::assertIsString(self::tool()([]));
    }

    public function testAnApprovedPlanIsKeptInTheState(): void
    {
        $result = self::tool()->resume(self::ARGUMENTS, new ResumeEntry('int_1', ResumeStatus::Resolved, ['approved' => 'true']));

        $plan = self::state()->plan;
        self::assertNotNull($plan);
        self::assertCount(2, $plan->searches);
        self::assertSame('exact', $plan->searches[1]->operator);
        self::assertSame(['motivations'], $plan->searches[0]->fields);
        self::assertStringContainsString('approuvé', $result);
    }

    public function testAnInstructionAsksForAnotherPlan(): void
    {
        $result = self::tool()->resume(self::ARGUMENTS, new ResumeEntry('int_1', ResumeStatus::Resolved, ['approved' => 'false', 'feedback' => 'Cherche aussi en chambre commerciale']));

        self::assertNull(self::state()->plan);
        self::assertStringContainsString('Cherche aussi en chambre commerciale', $result);
        self::assertStringContainsString('nouveau plan', $result);
    }

    public function testARefusalStopsTheSearch(): void
    {
        $result = self::tool()->resume(self::ARGUMENTS, new ResumeEntry('int_1', ResumeStatus::Resolved, ['approved' => 'false']));

        self::assertNull(self::state()->plan);
        self::assertStringContainsString('refusé', $result);
    }

    public function testAnAbandonedPlanIsNotApproved(): void
    {
        self::tool()->resume(self::ARGUMENTS, new ResumeEntry('int_1', ResumeStatus::Cancelled));

        self::assertNull(self::state()->plan);
    }

    private static function tool(): ProposeSearchPlan
    {
        return self::getContainer()->get(ProposeSearchPlan::class);
    }

    private static function state(): ResearchState
    {
        return self::getContainer()->get(ResearchState::class);
    }
}
