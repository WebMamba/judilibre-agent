<?php

declare(strict_types=1);

namespace App\Tests\Judilibre;

use App\Judilibre\Citation;
use App\Tests\Support\FakeJudilibre;
use PHPUnit\Framework\TestCase;

final class DecisionCatalogTest extends TestCase
{
    public function testADecisionIsFetchedOnceThenServedFromTheCache(): void
    {
        $judilibre = new FakeJudilibre();
        $catalog = $judilibre->catalog();

        $first = $catalog->find('65f1c0a2b4e3d2001a7c9e11');
        $second = $catalog->find('65f1c0a2b4e3d2001a7c9e11');

        self::assertSame('22-18.457', $first['number']);
        self::assertSame($first, $second);
        self::assertCount(1, $judilibre->requests);
    }

    public function testACitationReadsLikeALawyerWritesIt(): void
    {
        $decision = new FakeJudilibre()->catalog()->find('65f1c0a2b4e3d2001a7c9e11');

        self::assertSame('Cour de cassation, Chambre sociale, 13 mars 2024, n° 22-18.457', Citation::of($decision));
    }

    public function testACitationLeavesOutWhatJudilibreDoesNotKnow(): void
    {
        self::assertSame('Cour d\'appel de Lyon, 1er février 2022', Citation::of(['jurisdiction' => 'Cour d\'appel de Lyon', 'decision_date' => '2022-02-01']));
    }

    public function testTheLinkGoesToTheDecisionOnTheCourDeCassationWebsite(): void
    {
        self::assertSame('https://www.courdecassation.fr/decision/65f1c0a2b4e3d2001a7c9e11', Citation::url('65f1c0a2b4e3d2001a7c9e11'));
    }
}
