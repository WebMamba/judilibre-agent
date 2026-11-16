<?php

declare(strict_types=1);

namespace App\Tests\Ai\Tool;

use App\Ai\Tool\ListTaxonomy;
use App\Ai\Tool\PinDecision;
use App\Ai\Tool\SetFilters;
use App\Ai\Tool\UnpinDecision;
use App\Research\ResearchState;
use App\Research\SearchFilters;
use App\Tests\Support\FakeJudilibre;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class StateToolsTest extends TestCase
{
    public function testSetFiltersReplacesTheFilters(): void
    {
        $state = new ResearchState();

        $result = new SetFilters($state, self::validator())(['cc', 'ca'], ['soc'], [], ['b'], '2015-01-01', null);

        self::assertEquals(new SearchFilters(['cc', 'ca'], ['soc'], [], ['b'], '2015-01-01'), $state->filters);
        self::assertStringContainsString('Juridictions : cc, ca', $result);
    }

    public function testInvalidFiltersLeaveTheStateUnchanged(): void
    {
        $state = new ResearchState();

        $result = new SetFilters($state, self::validator())(['cour-supreme']);

        self::assertEquals(new SearchFilters(), $state->filters);
        self::assertStringContainsString('jurisdictions', $result);
    }

    public function testPinDecisionCitesTheDecisionFromJudilibre(): void
    {
        $state = new ResearchState();

        $result = new PinDecision($state, new FakeJudilibre()->catalog())('65f1c0a2b4e3d2001a7c9e11', 'Remplacement définitif');

        self::assertSame('Cour de cassation, Chambre sociale, 13 mars 2024, n° 22-18.457', $state->dossier[0]->title);
        self::assertSame('Remplacement définitif', $state->dossier[0]->note);
        self::assertStringContainsString('ajoutée au dossier', $result);
    }

    public function testPinningTwiceSaysSo(): void
    {
        $state = new ResearchState();
        $tool = new PinDecision($state, new FakeJudilibre()->catalog());
        $tool('65f1c0a2b4e3d2001a7c9e11');

        self::assertStringContainsString('déjà', $tool('65f1c0a2b4e3d2001a7c9e11'));
        self::assertCount(1, $state->dossier);
    }

    public function testAnUnknownDecisionIsNotPinned(): void
    {
        $state = new ResearchState();
        $judilibre = new FakeJudilibre()->respond('decision', FakeJudilibre::json('{}', 404));

        $result = new PinDecision($state, $judilibre->catalog())('nope');

        self::assertSame([], $state->dossier);
        self::assertStringContainsString('Judilibre', $result);
    }

    public function testUnpinDecision(): void
    {
        $state = new ResearchState();
        $state->pin('abc', 'A');

        self::assertStringContainsString('retirée', new UnpinDecision($state)('abc'));
        self::assertStringContainsString('pas dans le dossier', new UnpinDecision($state)('abc'));
    }

    public function testListTaxonomy(): void
    {
        $judilibre = new FakeJudilibre()->respond('taxonomy:chamber', FakeJudilibre::json('{"id":"chamber","result":{"soc":"Chambre sociale","civ1":"Première chambre civile"}}'));

        $result = new ListTaxonomy($judilibre->client())('chamber', 'cc');

        self::assertSame(['soc' => 'Chambre sociale', 'civ1' => 'Première chambre civile'], $result);
        self::assertSame(['id' => 'chamber', 'context_value' => 'cc'], $judilibre->query());
    }

    private static function validator(): ValidatorInterface
    {
        return Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
    }
}
