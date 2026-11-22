<?php

declare(strict_types=1);

namespace App\Tests\Ai\Tool;

use App\Ai\Tool\ListTaxonomy;
use App\Ai\Tool\SetFilters;
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
