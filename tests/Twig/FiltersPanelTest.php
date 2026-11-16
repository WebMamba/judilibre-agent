<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Tests\Support\FakeJudilibre;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;

/**
 * The user's side of the filters: every change is announced to the research controller
 * with research:filters-changed, which puts it in the shared state for the next run.
 */
final class FiltersPanelTest extends WebTestCase
{
    use InteractsWithLiveComponents;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    public function testItStartsOnTheCourDeCassationWithItsChambersFromJudilibre(): void
    {
        $html = (string) $this->panel()->render();

        self::assertMatchesRegularExpression('/value="cc"[^>]*checked/', $html);
        self::assertStringContainsString('Chambre sociale', $html);
        self::assertStringContainsString('Première chambre civile', $html);
        self::assertStringNotContainsString('appel de Lyon', $html, 'Seats only matter once the courts of appeal are searched.');
    }

    public function testAddingTheCourtsOfAppealShowsTheirSeats(): void
    {
        $panel = $this->panel()->call('toggle', ['field' => 'jurisdictions', 'value' => 'ca']);

        self::assertSame(['cc', 'ca'], $panel->component()->filters['jurisdictions']);
        self::assertStringContainsString('appel de Lyon', (string) $panel->render());
        $this->assertComponentDispatchBrowserEvent($panel, 'research:filters-changed')
            ->withPayload(['filters' => ['jurisdictions' => ['cc', 'ca'], 'chambers' => [], 'locations' => [], 'publications' => [], 'dateStart' => null, 'dateEnd' => null]]);
    }

    public function testTheLastJurisdictionCannotBeRemoved(): void
    {
        $panel = $this->panel()->call('toggle', ['field' => 'jurisdictions', 'value' => 'cc']);

        self::assertSame(['cc'], $panel->component()->filters['jurisdictions']);
    }

    public function testAChamberAndAPublicationAreToggled(): void
    {
        $panel = $this->panel()
            ->call('toggle', ['field' => 'chambers', 'value' => 'soc'])
            ->call('toggle', ['field' => 'publications', 'value' => 'b'])
            ->call('toggle', ['field' => 'chambers', 'value' => 'soc']);

        self::assertSame([], $panel->component()->filters['chambers']);
        self::assertSame(['b'], $panel->component()->filters['publications']);
    }

    public function testUnknownValuesAreIgnored(): void
    {
        $panel = $this->panel()
            ->call('toggle', ['field' => 'jurisdictions', 'value' => 'cour-supreme'])
            ->call('toggle', ['field' => 'plan', 'value' => 'x']);

        self::assertSame(['cc'], $panel->component()->filters['jurisdictions']);
        self::assertArrayNotHasKey('plan', $panel->component()->filters);
    }

    public function testThePeriodIsSetAndCleared(): void
    {
        $panel = $this->panel()->set('filters.dateStart', '2015-01-01');
        self::assertSame('2015-01-01', $panel->component()->filters['dateStart']);
        $this->assertComponentDispatchBrowserEvent($panel, 'research:filters-changed');

        $panel->set('filters.dateStart', '');
        self::assertNull($panel->component()->filters['dateStart']);

        $panel->set('filters.dateEnd', 'demain');
        self::assertNull($panel->component()->filters['dateEnd'], 'Not a date: undone.');
    }

    public function testItStillWorksWhenJudilibreIsDown(): void
    {
        self::getContainer()->get(FakeJudilibre::class)->respond('taxonomy:chamber', FakeJudilibre::json('{}', 500));

        $html = (string) $this->panel()->render();

        self::assertStringContainsString('Cour de cassation', $html);
        self::assertStringNotContainsString('Chambre sociale', $html);
    }

    private function panel(): TestLiveComponent
    {
        return $this->createLiveComponent('FiltersPanel', client: $this->client);
    }
}
